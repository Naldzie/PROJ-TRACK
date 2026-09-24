# DAY-COLUMN S-CURVE LAYOUTS (ONE COLUMN PER CALENDAR DAY)
# Parent refs: textfiles/document_parser_excel_learning_loop_plan.md (E1 — maps, teaching, learn-from-AI)
#              textfiles/document_parser_pdf_teach_grid_plan.md (the teach desk)
#              textfiles/local_llm_parser_plan.md (why the AI tier cannot carry this alone)
# Date: September 16, 2026 (last updated September 18, 2026)
# Status: ⏸ PARKED 2026-09-18 — the remaining steps are parked by the user's order, on a
#         CLIENT FACT: the PMU confirmed they mainly receive the ORIGINAL EXCEL files for
#         S-curves, so the exotic-layout work this file opened for is no longer the thing
#         standing between the system and real use. Priority moved to pilot-testing
#         readiness (textfiles/pilot_testing_readiness_plan.md).
#         ✅ (A) date-row rule — COMMITTED 31e6b10 on phase-21-scurve-date-row, MERGED to
#            develop, browser-proven.
#         ✅ Restudy for (B)-(E) — done, no code (§6).
#         ✅ Steps 1-3 (D1 merged spans · D3 calendar names · D2 60 months) — BUILT,
#            BROWSER-PROVEN, and COMMITTED 9545cc1 on phase-22-scurve-month-headers.
#         ⏸ Step 4 (month-name header rule) · ⏸ step 5 (teach a month as a range) —
#            PARKED 2026-09-18, not abandoned. Both stay fully specified in §4/§6 and can
#            be un-parked whenever a contractor file actually needs them.
#         ⏸ PDF counterpart restudy — parked with them (same reason: the S-curve arrives
#            as Excel; the PDF path is the fallback, not the main road).
#         ⏸ option E deferred (unchanged).
# Branch: phase-22-scurve-month-headers (off develop c081c39; Rule 12) — steps 1-3
#         committed as 9545cc1; nothing from this file is uncommitted.

===============================================================================
  0. READ THIS FIRST
===============================================================================

  🔴 NEWEST (2026-09-24) — READ §7 FIRST. A REAL DAILY-FORMAT CONTRACTOR WORKBOOK
    FINALLY ARRIVED (sampledocs/CONSTRUCTION SCHEDULE.xlsx) and was measured through
    the live reader.
      ✅ The date-row rule (option A, phase-21) WORKS on it — first proof on a real
         file rather than a rebuild. Layout, month spans and meta all correct.
      ❌ The CURVE reads 0 / 0 / 0 / 100 against a true 3.04 / 10.10 / 33.75 / 100,
         because the day cells hold FRACTIONS OF THE CONTRACT (TOTAL% / DURATION),
         not pesos, and buildMonths divides them by the contract total again.
         This is the known "fraction scale" defect and THIS IS ITS FIRST REAL
         SPECIMEN — the condition AGENTS.md parked it under is now met.
      ⚠ Worse than the number: the existing summary rescue does not fire (it needs
         activity math ABSENT, not WRONG), so the user is handed a flat curve with
         "no action needed" printed beside the correct one.
      ⚠ §7 DOES NOT UN-PARK STEPS 4/5 — different problem, see §7.5.
    Nothing is built and nothing is ordered. Details, arithmetic and the shape of a
    fix are in §7.

  ➜ HANDOFF (2026-09-18) — STEPS 4/5 OF THIS FILE ARE PARKED.
    WHY IT IS PARKED (user, 2026-09-18): the client confirmed they mainly have the
    ORIGINAL EXCEL files for S-curves. Everything this file exists for — reading a
    contractor's exotic printed layout — matters much less once the source workbook is
    what actually arrives. The PMU's real document problem is elsewhere: their NORMAL
    documents (contracts, proposals, supporting papers) are mostly SCANNED, because they
    are handed physical copies. That moves the pressure to the normal-doc OCR path, not
    to S-curve layout exotica. Priority is now pilot-testing readiness —
    textfiles/pilot_testing_readiness_plan.md is the active file.
    WORKING TREE: branch phase-22-scurve-month-headers. Steps 1-3 are COMMITTED as
    9545cc1 (the earlier "do not commit for now" order was lifted). Nothing from this
    file is uncommitted.
    STATE AT THE PARK: PHP 1226 passed / the same 5 pre-existing failures; JS 230 / 228 +
    the 2 intended blinding fails; all 10 sampledocs workbooks byte-identical to develop.
    Browser-proven: the two calendar fixtures, the teach desk past Month 12, and an
    unchanged existing import (§6).
    READ, IN ORDER, ONLY IF THIS IS UN-PARKED: §0 (this), then §6's last four entries —
    the restudy (defects D1-D3, the teaching path end to end, the recommended order), the
    steps 1-3 build, its browser result, and the 2026-09-18 park entry.
    PARKED WORK, EACH STILL FULLY SPECIFIED AND EACH NEEDING AN ORDER TO RESUME (Rule 10):
      - step 4: a month-name header rule so a DATELESS day-column file (S1) reads
      - step 5: teach a month as a first..last range, wider captures, 4000-char limit
      - PDF counterpart: scurve-pdf-parser-core.js keys month names by calendar number
        and caps numbered labels at 12 — its own restudy first
    WHAT WOULD UN-PARK THIS: a real contractor S-curve that arrives WITHOUT its workbook
    and that the date-row rule, the calendar-name rule and hand-teaching all fail to read.
    Until then this is speculative work against a problem the client does not have.
    FIXTURES (sampledocs/, git-ignored): SCurve-Test-CONSCHED-Daily-{values,formulas},
    SCurve-Test-Calendar-DecStart-4Month, SCurve-Test-Calendar-14Month. Generators and
    probes live in C:\temp\opencode (listed per entry in §6). The before/after gate is
    snapshot_excel_reads.php (compare against excel_reads_after.json).

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
  4. OPTIONS (⏸ (C)/(D)/(E) PARKED 2026-09-18 — see §0 and the park entry in §6)
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
  (B) CALENDAR NAMES -> PROJECT ORDER.  ✅ DONE as D3 in steps 1-3 (committed 9545cc1).
      Map month names by position, not by calendar number, whenever they are not
      already 1..N in order. Small, and useful without (A) for monthly layouts that
      start mid-year.
  (C) ⏸ PARKED 2026-09-18. EXCEL MONTH AS A RANGE in maps (first..last column), as PDF
      already does. Lets a reviewer and the AI teach what (A) misses. Touches the map
      format, teachColumns validation, the desk, and the AI sanitizer. (The restudy later
      found NO MAP_VERSION bump is needed — monthSpans is already a plain column list, so
      a range expands server-side into the same list. Read that before restarting this.)
  (D) ⏸ PARKED 2026-09-18, PARTLY SUPERSEDED. LIFT THE CAPS. The 12-month limit was
      already lifted to 60 by step 3 (buildTaughtMap, teachColumns, the desk). What stays
      parked is the capture side: the 80-column map/slice limit and the 4000-char block
      store limit. A day-column file outruns 80 columns in under 3 months.
  (E) ⏸ DEFERRED (unchanged). FOR THE AI SLICE: summarize day columns before sending
      (e.g. send per-month column sums once (A) knows the months) instead of raw cells.
      Only matters if the AI tier comes back (local_llm_parser_plan.md).

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

  2026-09-16 — RESTUDY FOR THE REMAINING LAYOUT WORK (B)-(E). READ-ONLY, NO APP CODE.
  (User: "lets go with the more layout work … do a restudy first".) One probe edit to
  the service was applied and restored byte-exact (git diff clean).

    THREE DEFECTS FOUND IN THE RULE READER (all silent — no warning is raised)

    D1 MERGED MONTH CELLS ARE IGNORED ON A SINGLE-ROW HEADER (off-by-one).
       finalizeHeader looks for merges on range(firstRow + 1, lastRow + 1). That +1 is
       right for the TWO-row pass (positions, 0-based) and wrong for the single-row pass
       (row KEYS, already 1-based) — it inspects the row BELOW the header.
         S3a  "Month 1" merged C:E, "Month 2" F:H, 10k in every cell
              today: spans {1:[C],2:[F]}, curve 16.67 / 100   (truth 50 / 100)
         S3c  the same merges placed one row LOWER: read correctly — proves the offset.
       Uniformly paired months (BUHI) escape it only because the "next month two
       columns away" pairing rule rebuilds the pair.
       Fix measured: sheetRows = [firstRow] for a single-row detection. S3a/S3b then
       read 50 / 100, and ALL 10 sampledocs workbooks stay byte-identical
       (excel_reads_mergefix.json).

    D2 MONTHS AFTER 12 ARE DROPPED. matchMonthLabel returns null for "Month 13".
         S4   Month 1..14, 10k each: 12 months read, month 12 forced to 100 (it was
              78.57) — two months of work vanish from the baseline.
       The 12 appears in: matchMonthLabel (every family), bare numbers (:424),
       longestNumberRun, buildTaughtMap (:846 'bad-month-number'), teachColumns
       (months max:12), the desk (SCH_TEACH_MAX_MONTHS = 12), suggestedMonthsFor (PDF
       cap). The AI side already accepts 1..60 everywhere; the import UI has no cap
       (normalizeSCurveMonths dates start + (index - 1), pins overflow to the end date
       and already warns about collisions).

    D3 CALENDAR NAMES ARE CALENDAR NUMBERS, NOT PROJECT MONTHS (option B).
         S2   DECEMBER JANUARY FEBRUARY MARCH (one column each)
              today: month_index 1, 2, 3, 12 — summed Jan -> Feb -> Mar -> Dec. The
              curve only LOOKS right (25/50/75/100) because every month holds 10k; the
              UI then dates "month 12" at start + 11 months (pinned to the end date).
         S2   the same with merged pairs: 25 / 50 / 62.5 / 100 (truth 25/50/75/100) —
              D1 and D3 together: December and March each lost a column.
         S2c  OCT..MAR: month_index 1,2,3,10,11,12.
         S2b  JANUARY..APRIL: correct (the only case that works).
       A project that starts in any month but January is misread today.

    WHAT A DAY-COLUMN FILE WITHOUT DATES LOOKS LIKE (S1: CONSCHED with its date rows
    removed — "#1..#90" plus merged DECEMBER/JANUARY/… only)
      Rules refuse it and auto-map is null: the names sit in row 11 under "#N" in row 10,
      so the two-row pass reads "#1 DECEMBER"; row 11 alone has no DESCRIPTION.
      The capture (serializeForAi(path, 15)) is 3,561 chars, 15 rows, 80 columns: the
      month-name row shows DECEMBER / JANUARY / FEBRUARY but NOT MARCH (column CL is past
      CB), and only 4 activity rows fit. So a reviewer could not even SEE March to teach it.

    THE TEACHING PATH, END TO END (what option C/D would touch)
      capture   collect() -> serializeForAi(path, 15): 80-column cut, 15-row budget,
                stored mb_substr(…, 4000). The DB column is TEXT (65k) — 4000 is an app
                limit, repeated in three places in the controller + the model.
      desk      schTeachClickLetter: "At most two columns per month" (list.length < 2);
                SCH_TEACH_MAX_MONTHS 12; prefillFromMap shows stored columns as-is.
      endpoint  teachColumns: months max:12, months.* max:2 letters, header_rows ≤ R40.
      map       buildTaughtMap: months 1..12, columns 0..79, spans disjoint, first column
                of each month strictly increasing. monthSpans is ALREADY a plain list of
                columns — a range can be expanded server-side into that same list, so
                NO map-format change and NO MAP_VERSION bump is needed.
      reader    findHeaderViaMaps: sig row + mapSpansHoldNumbers, then buildMonths sums
                every listed column. No width or month cap on the read side.
      AI learn  sanitizer keeps ≤4 letters per month; the prompt asks for 1-2.
      Stored data: the live DB holds 2 Excel layouts, 11 month spans, ALL single-column
      (probe_excel_maps.php). Test fixtures use adjacent pairs (I+J) and overlap
      refusals (I+J / J+K). So "two letters = first..last range" changes the meaning of
      NO stored layout and no test — adjacent pairs expand to themselves.

    RECOMMENDED ORDER (each step small, measured against the 10-workbook snapshot)
      1. D1 merge off-by-one. One line + a test. Measured corpus-neutral.
      2. D3 / option B: number calendar-named months by COLUMN ORDER (so Dec..Mar reads
         1..4 and a repeated name 12 months later becomes month 13). Only when the
         header's months are calendar names; "Month N" labels keep their numbers.
      3. D2: lift 12 -> 60 (the AI's existing ceiling) for labelled months, maps, the
         teach endpoint and the desk; bare numbers stay 1..12 (ambiguous by nature).
         Goes with 2, because a 14-month calendar-named file repeats two names.
      4. Month-name header rule (new, S1): a row of calendar names / "Month N" labels,
         with DESCRIPTION on it or up to 3 rows above — the date pass's twin. Spans from
         the merges (needs 1) or up to the next label. Makes S1 read on first upload.
      5. Option C + D, teaching wide layouts: a month taught as first..last letter
         (server expands; adjacent pairs unchanged), capture wider than 80 columns
         (at least for capture), the 4000-char store limit raised, column cap 79 lifted.
         Largest step; touches capture, desk, endpoint, map builder and the JS suite.
      6. Option E (AI slice for wide files): DEFER. Gemini already gets the .xlsx
         attached; only a local model would need it, and step 1 of the local-LLM plan
         says no local model is fit for this.

    PROBES (C:\temp\opencode, read-only): probe_layout_restudy.php + probe_layout_restudy2.php
    -> layout_restudy.log; probe_excel_maps.php; excel_reads_mergefix.json;
    SCurveImportService_restudy_real.php (the pre-probe copy used to restore).

  2026-09-16 — STEPS 1-3 ✅ BUILT (D1 merged spans · D3 calendar names · D2 60 months).
  Branch phase-22-scurve-month-headers (off develop c081c39). UNCOMMITTED — user order:
  "start on a new branch. do not commit for now".

    WHAT CHANGED
      SCurveImportService
        MAX_MONTHS = 60           new constant (the AI sanitizers' existing ceiling).
        finalizeHeader            merges are read on [firstRow] for a single-row
                                  detection (row keys), range(first+1, last+1) only for
                                  the two-row pass (positions). D1.
        matchMonthLabel           numbered families accept 1..MAX_MONTHS; calendar names
                                  moved out. D2.
        calendarMonthNumber       new — recognises a calendar name (the duplicate 'may'
                                  key of the old table dropped; same lookups).
        detectHeaderRow           calendar-named cells collected in column order and
                                  numbered 1..N, ONLY when the row has no numbered month
                                  labels. Bare numbers stay 1..12. D3.
        buildTaughtMap            months 1..MAX_MONTHS.
      SCurveHeaderProposalController::teachColumns   months max:MAX_MONTHS.
      _manage_scurve_headers_modal.blade.php         SCH_TEACH_MAX_MONTHS from the PHP
                                  constant (the "+ month" button now goes to 60; a short
                                  layout's palette is unchanged). view:cache OK.
      Deliberately NOT changed: suggestedMonthsFor's cap of 12 for an UNNUMBERED PDF
      capture (a starting suggestion only, pinned by a JS test).

    RESULT (restudy probes re-run)
      S3a/S3b merged "Month N" over 3 cols   50 / 100 (was 16.67 / 100)
      S3c merges on the row BELOW             no longer read as spans (correct)
      S2 DEC..MAR single / merged pairs       months 1-4, 25/50/75/100 (was 1,2,3,12 / 62.5)
      S2c OCT..MAR                            months 1-6 (was 1,2,3,10,11,12)
      S2b JAN..APR                            unchanged
      S4 Month 1..14                          14 months, 85.71 at month 12 (was 12, forced 100)
      S1 CONSCHED without dates               still refused — step 4 (not ordered)
      sampledocs, all 10 workbooks            BYTE-IDENTICAL (excel_reads_phase22.json vs
                                              excel_reads_after.json: result, auto-map, slice)

    VERIFICATION
      +4 tests: SCurveImportTest (merged single-row spans, calendar names in column order
      with unequal amounts + merged pairs + a 14-month repeat, labelled months past 12);
      ScurveHeaderProposalTest (a 14-month teaching saves; month MAX_MONTHS+1 refused).
      Falsified (mutate_p22.mjs), five mutations, each red: off-by-one restored ·
      calendar numbering off · ceiling back to 12 · map-builder cap 12 · teach max:12.
      php artisan test   1226 passed / the same 5 pre-existing failures BY NAME.
      npm run test:js    230 tests, 228 pass + the 2 intended blinding fails.

    BROWSER CHECK OWED (user)
      1. Import an Excel whose month header is DECEMBER..MARCH (or any non-January
         start): months dated from the project start in file order, no gap.
      2. S-Curve Layouts -> Teach an Excel row -> "+ month" keeps offering buttons past
         Month 12, and a 13+ month teaching saves.
      3. An existing Excel (BUHI / roof) imports exactly as before.

    BROWSER RESULT (user, same day)
      Step 2 ✅ "+ month" goes past Month 12 and a longer teaching saves.
      Step 3 ✅ existing Excel imports as before.
      Step 1 — the user imported the CONSCHED day-column rebuild: 18.24 / 58.46 / 91.16
        / 100, 11/9/16/9 — correct, but that file reads through its DATE ROW (phase 21),
        not the calendar-name header, so it does not exercise D1/D3. Two dedicated
        browser fixtures were built for it (make_calendar_fixtures.php, sampledocs/,
        git-ignored), each checked by hand:
          SCurve-Test-Calendar-DecStart-4Month.xlsx  DECEMBER..MARCH merged pairs,
            unequal amounts: months 1-4, 10 / 35 / 65 / 100, counts 2,2,2,1,
            contract 2,000,000, no notes. (Before: months 1,2,3,12 with lost columns.)
          SCurve-Test-Calendar-14Month.xlsx  DECEMBER .. JANUARY (14 names, two
            repeated): months 1-14, 2.86 / 5.71 / 13.57 … 92.14 / 100, counts
            1,1,2,…,2, contract 700,000, no notes.
      Step 1 ✅ PASSED with both fixtures (user, same day), exactly as predicted:
        DecStart-4Month   10.00 / 35.00 / 65.00 / 100.00, activities 2,2,2,1, names in
                          the right months (Mobilization only in month 1, Roofing and
                          Finishing alone in month 4).
        14Month           14 months July 2025 .. August 2026, 2.86 / 5.71 / 13.57 /
                          21.43 / 29.29 / 37.14 / 45.00 / 52.86 / 60.71 / 68.57 / 76.43 /
                          84.29 / 92.14 / 100.00, activities 1,1 then 2 — no date
                          collision note (the test project's dates hold 14 months).
      => STEPS 1-3 ARE BROWSER-PROVEN. Still uncommitted by the user's order.

    OPEN / NOT DONE
      - THE PDF READER HAS THE SAME TWO LIMITS (scurve-pdf-parser-core.js:
        MONTHS table keyed by calendar number at :79, ordinal/numbered caps of 12 at
        :77 and the learned-word generalisation). Not touched — this order was the Excel
        reader. Its own restudy first: PDF month bands are built from label positions,
        and the corpus gates are verify_p6 / block_snapshot.
      - Step 4 (month-name header rule for dateless day-column files) and step 5
        (teaching a month as a range, wider captures) not started.

    PROBES: excel_reads_phase22.json, mutate_p22.mjs, *_p22_real.php (pre-mutation
    copies), php_p22.log, js_p22.log.

  2026-09-18 — ⏸ PARKED (steps 4 and 5, and the PDF counterpart). NO APP CODE.
  User order: "an update those step 4 and 5 i want to park it for now."

    THE CLIENT FACTS BEHIND IT (user, 2026-09-18) — the reason this is a park and not a
    pause, and the single most decision-changing thing learned about the parser track:
      1. THE PMU MAINLY HAS THE ORIGINAL EXCEL FILES for S-curves. The whole premise of
         this file was a contractor schedule we only had as a PRINT (CONSCHEDSAVINGS.pdf,
         §1). If the workbook itself is what normally arrives, phpspreadsheet reads it
         exactly, offline, with no AI and no teaching — which is the path that already
         works for every workbook in the corpus.
      2. THEIR NORMAL DOCUMENTS ARE MAINLY SCANNED. Contracts, proposals and supporting
         papers reach the PMU as PHYSICAL COPIES and are scanned in. That is the opposite
         of the S-curve situation: no source file exists at all, and the parser is facing
         an image, not a document with a text layer.
    CONSEQUENCE FOR PRIORITY: the pressure moves off S-curve layout exotica and onto the
    NORMAL-DOC OCR path (textfiles/ocr_page_preview_and_ai_picker_plan.md, and the OCR
    batch in document_parser_learning_loop_plan.md §6). Recorded here so the next session
    does not re-open steps 4/5 on momentum.

    WHAT IS PARKED, AND WHAT SURVIVES THE PARK
      step 4  month-name header rule for a DATELESS day-column file (S1). Spec intact in
              §4 and in the restudy's "RECOMMENDED ORDER". Nothing built.
      step 5  option C + D — teach a month as first..last, captures wider than 80 columns,
              the 4000-char store limit, the 79-column map cap. Spec intact; the restudy's
              finding that monthSpans is ALREADY a plain column list (so a range expands
              server-side with NO MAP_VERSION bump) is the thing to re-read first.
      PDF     scurve-pdf-parser-core.js still keys calendar month names by CALENDAR NUMBER
              (:79) and caps numbered/ordinal labels at 12 (:77) — the same two defects
              fixed on the Excel side as D3/D2. STILL PRESENT, deliberately. A PDF S-curve
              starting in any month but January is misread today. Lower priority now that
              the Excel is the expected source, but it is a KNOWN DEFECT, not a gap —
              if the pilot surfaces a PDF-only S-curve, this is the first thing to fix.
      All fixtures, probes and the before/after gate (snapshot_excel_reads.php vs
      excel_reads_after.json / excel_reads_phase22.json) stay where they are.

    WHAT IS NOT PARKED: everything already built and committed — the date-row rule
    (31e6b10) and the month-header fixes D1/D2/D3 (9545cc1). Those are live behaviour and
    are covered by the suite; they are not affected by this decision.

    UN-PARK CONDITION: a real contractor S-curve that arrives without its workbook AND
    defeats the date-row rule, the calendar-name rule and hand-teaching. Pilot testing is
    exactly the thing likeliest to produce one — so revisit after the pilot, with a real
    file in hand rather than a rebuilt fixture.

    NEXT ACTIVE FILE: textfiles/pilot_testing_readiness_plan.md.


===============================================================================
  7. THE FIRST REAL DAILY-FORMAT WORKBOOK — MEASURED 2026-09-24
     ⚠ A REAL DEFECT. READ THIS BEFORE §0's PARK NOTE, WHICH IT DOES NOT CANCEL.
===============================================================================

  THE FILE: sampledocs/CONSTRUCTION SCHEDULE.xlsx (JVM Ceguera Construction,
  "ANALYSIS DESIGN AND RETROFITTING WORKS FOR THE 2ND FLOOR STRUCTURAL MEMBERS OF
  SUPPLY AND PROPERTY BUILDING", CSPC). 1 sheet, A1:DD204, 108 columns.
  Supplied by the user 2026-09-24. ⚠ THIS IS THE REAL THING, not a rebuild — every
  earlier daily-format measurement in this file was made against Excel
  RECONSTRUCTIONS of CONSCHEDSAVINGS.pdf, because no genuine workbook existed.

  7.1 LAYOUT (read, not assumed)
      R11  M = "YEAR 2026"
      R12  A=ITEM #  B=DESCRIPTION  C=UNIT  D=QTY.  E=UNIT PRICE  F=Amount
           G=TOTAL %  H=DAILY ACCOMP.  I=DURATION (DAYS)
           month names on the SAME row: M=JANUARY  AD=FEBRUARY  BF=MARCH  CG=APRIL
      R13  THE DATE ROW — M=2026-01-15 … CX=2026-04-14, one column per calendar day
      R16  activity 1  DESIGN AND ANALYSIS   F=42,000.00      G=0.0536  H=0.0018  I=30
      R18  activity 2  Retrofitting Works    F=741,033.51     G=0.9464  H=0.0158  I=60
      R19  CONTRACT COST F=783,033.51  G=1   I="DAILY ACCOMPLISHMENT"
      R20  "% OF ACCOMPLISHMENT"            M=0.0304  AD=0.0706
      R21  "COMMULATIVE % OF ACCOMPLISHMENT" M=0.0304  AD=0.1010   <- THE TRUE CURVE

  7.2 ✅ WHAT WORKS — AND IT IS THE PART THAT WAS NEVER PROVEN
      The date-row rule (option A, phase-21, 31e6b10) FIRES CORRECTLY ON A REAL FILE.
      deriveAutoColumnMap returns rowHint=12, descCol=1, contractCol=5 and month
      spans grouped by calendar month exactly as printed:
          month 1 = cols 12-28  (M..AC  = Jan 15-31, 17 days)
          month 2 = cols 29-56  (AD..BE = Feb 1-28,  28 days)
          month 3 = cols 57-..  (BF..   = Mar)
          month 4 = ..          (CG..   = Apr)
      Meta is right too: project name, location and contractAmount 783,033.51.
      ⚠ WORTH STATING PLAINLY: phase-21 was built and browser-proven against two
      REBUILDS and nothing else. A real contractor file has now exercised it and the
      layout half holds up. That is a genuine result, not a formality.

  7.3 ❌ THE DEFECT — THE CURVE READS 0 / 0 / 0 / 100
      parse() returns planned_progress_percent 0, 0, 0, 100 against a true curve of
      3.04 / 10.10 / 33.75 / 100. weightSum=0, activityCount=2, cumulativeRow=null,
      curveSource='activities'.

      ROOT CAUSE, ARITHMETIC, NOT INFERRED: the day cells do NOT hold pesos. They
      hold a FRACTION OF THE WHOLE CONTRACT PER DAY, i.e. TOTAL% / DURATION:
          activity 1:  G 0.0536 / I 30 = 0.001787  = H 0.0018  ✓
          activity 2:  G 0.9464 / I 60 = 0.015773  = H 0.0158  ✓
      And that reproduces the author's own figure exactly:
          month 1 = 17 days x 0.001787 = 0.03038 = 3.04%  = R21's M cell  ✓
      But buildMonths (:2035) computes
          planned % = (monthPesoTotal / contractTotal) * 100
      treating those fractions as PESOS. So month 1 becomes
          0.0306 / 783,033.51 * 100 = 0.0000039%  ->  rounds to 0.00.
      ⚠ THIS IS THE KNOWN, KNOWINGLY-CARRIED "FRACTION SCALE" DEFECT, and this file
      is ITS FIRST REAL SPECIMEN. AGENTS.md carried it as "untriggered … fixing it
      would move summaryReference on files there is no specimen for". THE SPECIMEN
      NOW EXISTS. That condition is met; the reasoning that parked it is spent.

      ⚠ NOTE THE ASYMMETRY — HALF THE READER ALREADY KNOWS ABOUT FRACTIONS:
      readSummaryReference (:2282) does
          $pct = ($v > 0 && $v < 1.5) ? round($v * 100, 2) : round($v, 2);
      which is why summaryReference reports the TRUE 3.04 / 10.10 / 33.75 / 100.
      The summary path scales; the activity path does not. One reader, two opinions
      about what a number under 1.5 means.

  7.4 ⚠ WHY THE EXISTING RESCUE DID NOT FIRE (this is the part that bites)
      The summary fallback at :2086 adopts the author's curve — but only when
          $lastNonZero === 0 && count($summaryReference) >= 2
      Activity math here is not ABSENT, it is WRONG BY A FACTOR OF ~783,000. It
      produces tiny non-zero values, and :2065 then forces the final month to 100.0.
      So $lastNonZero is not 0, the gate stays shut, and the rescue built for exactly
      this shape of problem is stepped over.

      ⚠ AND THE USER IS TOLD NOTHING IS WRONG. The three warnings emitted read:
        "Heads-up (Month 1): the file's own summary says 3.04%, but its activity rows
         add up to 0%. The summary was likely computed against a different total.
         Your baseline below uses the activity math — NO ACTION NEEDED."
      The correct curve is printed inside a warning that recommends ignoring it, and
      the baseline handed over is flat zero. A reviewer who trusts that line locks a
      worthless baseline. THE WRONG ADVICE IS WORSE THAN THE WRONG NUMBER: a blank
      curve invites scrutiny, "no action needed" disarms it.

  7.5 WHAT THIS DOES *NOT* CHANGE
      ⚠ STEPS 4 AND 5 STAY PARKED. Their un-park condition is a real S-curve that
      arrives WITHOUT its workbook and defeats the date-row rule, the calendar-name
      rule and hand-teaching. This file arrived WITH its workbook and its layout read
      correctly, so it satisfies none of that. This is a separate defect in cell
      SEMANTICS, not a gap in layout teaching. Do not use §7 to reopen §4's options.

  7.6 PROBES (C:\temp\opencode, outside the repo per convention — NOT pushed)
      probe_consched_real.php    sheet inventory
      probe_consched_head.php    <file> <fromRow> <toRow> — header/date-row dump
      probe_consched_rows.php    <base> <file> <from> <to> — activity rows + day cells
      probe_consched_parse.php   <base> <file> — deriveAutoColumnMap + parse summary
      probe_consched_months.php  <base> <file> — full months/meta JSON
      ⚠ The PHP probes take the PROJECT BASE as argv[1] and boot the real Laravel
      container, so they read through the live service, not a copy of it.
      ⚠ phpspreadsheet 4.x: getCellByColumnAndRow() is GONE — use getCell([$col,$row]).

  7.7 ⬜ NOT BUILT, NOT ORDERED — the shape a fix would take
      (a) Detect the scale of activity/day cells instead of assuming pesos. The file
          states its own units: G=TOTAL % per activity, F=Amount, and a CONTRACT COST
          row whose G is exactly 1. When the activity cells are fractions of 1 rather
          than money, percent = sum(cells) * 100 and no division by the contract
          happens at all.
      (b) OR widen the summary-fallback gate so "activity math is implausible"
          counts, not only "activity math is absent" — e.g. when the activity curve
          disagrees with a present summary column by orders of magnitude.
      ⚠ (a) is the honest fix and (b) is a safety net; they are not alternatives and
      (b) without (a) still silently prefers a summary over a curve it could have
      read correctly.
      ⚠ EITHER WAY, RE-MEASURE THE WHOLE CORPUS. The scale rule touches every Excel
      S-curve, and the existing gate is what currently silences BUHI's fraction
      notes — moving it moves summaryReference, and summaryReference is what drives
      the AI-assist offer. Before/after gate: snapshot_excel_reads.php against
      excel_reads_after.json, all 10+ sampledocs workbooks byte-identical except the
      one under test.

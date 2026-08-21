# PDF DOCUMENT PARSER IMPROVEMENT + S-CURVE BASELINE AUTO-IMPORT

CSPC PMU System — two related implementations on the create-project wizard's
document uploads:

- **PHASE 1 (PRIORITY — do this FIRST):** fix and extend the PDF text-extraction /
  autofill feature (`document-parser.js`) that reads project info out of uploaded
  PDFs and pre-fills the create-project form.
- **PHASE 2 (NEXT, after Phase 1 lands):** automate baseline setup by importing a
  planned S-curve (Excel or PDF) into the existing "Set Up Baseline" modal.

Status: ✅ Phase 1 — IMPLEMENTED on branch `phase-1-document-parser-improvement`
        (pending review/merge, Rule 12)
        ✅ Phase 2 — IMPLEMENTED on branch `phase-2-scurve-baseline-import`
        (pending review/merge, Rule 12)
        ✅ Phase 3 (PDF S-curve) — IMPLEMENTED on branch `phase-3-pdf-scurve-import`
        (uncommitted, pending review/merge, Rule 12)
        🔜 Phase 3 follow-up — Gemini OCR last-resort (PLANNED)
        (see "Phase 3 implementation log + Gemini OCR plan" below)
        🔜 Phase 4 — Document viewer enhancement + extension attachments fix
        (PLANNED — see "Phase 4" section near end of file)
Date:   August 21, 2026
Branch: Phase 1 on `phase-1-document-parser-improvement`; Phase 2 branched
        FROM the Phase 1 branch (user decision) as `phase-2-scurve-baseline-import`;
        Phase 3 (PDF S-curve) branched FROM phase-2 as `phase-3-pdf-scurve-import`
        (uncommitted work on the branch).

IMPLEMENTATION LOG — Phase 1 (2026-08-19):
  - Branch phase-1-document-parser-improvement created from main (commit 2750f53).
  - document-parser-core.js (NEW, ESM): line grouping (Y-tolerance + X-sort),
    X-gap spacing + hasEOL, findLabeledValue (same-line + next-line), all field
    extractors (label-anchored budget with "(ABC)", date/duration normalizers),
    parseToInputDate/parseDurationToDays/toTitleCase.
  - document-parser.js (REWRITTEN): uses core, _autofilledFields tracking (only
    autofill-written fields are cleared — user input preserved), preview-then-
    apply flow (preview modal, no instant fill), OCR fallback via lazy
    tesseract.js (first 10 textless pages), 50-page cap + early-exit once all
    8 fields found, success banner REMOVED (per-field indicators remain).
  - pdfjs-loader.js (NEW): PDF.js jsdelivr → unpkg CDN fallback, window.pdfjsLib.
  - form-navigation.js: runDocumentAutofill() no longer awaited (background run).
  - app.blade.php + stafflayouts.blade.php: inline PDF.js module replaced by
    pdfjs-loader.js.
  - _create_project_modal.blade.php: "Preview Extracted Data" button on Page 1,
    @include autofill-preview-modal, core loaded via type="module".
  - components/autofill-preview-modal.blade.php (NEW) + CSS.
  - tests/js/document-parser-core.test.mjs (NEW, node --test, 17 tests) +
    package.json "test:js" script.
  - Verification: npm run test:js 17/17 pass; composer test 713 passed /
    2753 assertions (2026-08-19).

IMPLEMENTATION LOG — Phase 1 follow-ups (2026-08-19, commit 5ba98a4):
  - BUG: lines sorted ASCENDING by y, but PDF.js y is bottom-up (origin bottom-
    left), so pages came out upside-down (description swallowed the lines ABOVE
    it — "Location:" + "Project title:"). groupItemsIntoLines now sorts
    DESCENDING (top of page = largest y).
  - BUG: extractDescription was a raw-text regex capturing until blank line/EOF.
    Rewritten line-based; stops at the next field label (budget/contractor/
    dates/workers), so the description no longer grabs the rest of the doc.
  - Preview modal rows are now editable inputs; Apply reads corrected values
    (dates normalized to YYYY-MM-DD).
  - _autofillApplied flag: the Page1→2 Next hook skips re-scanning once a
    preview was applied; reset on modal open.
  - Cache-busting: filemtime ?v= query on touched script tags.
  - tests/js expanded to 19 tests (regression: exact test PDF, bottom-up coords,
    scrambled item order). composer test 713 passed / 2751 assertions.

IMPLEMENTATION LOG — Phase 2 planning (2026-08-19):
  - Client samples uploaded to sampledocs/ (STEP 0 dependency satisfied):
    Updated-S-Curve-3-Storey-Bldg.CSPC-Buhi.xlsx = canonical Excel S-curve
    (single sheet "S-CURVE"); CONSCHEDSAVINGS.pdf = chart-only (no text layer);
    Contract/Quantity-Sheet PDFs = Phase 1 autofill test material (not S-curves).
  - User decisions: branch Phase 2 FROM the Phase 1 branch; month planned_date
    derived by offsetting the project's planned start date; SCOPE = EXCEL import
    first (PDF text-layout importer deferred; chart-only/PDF gets a clear
    "upload the .xlsx" message).
  - Full sample analysis + refined Phase 2 implementation below (§4).

IMPLEMENTATION LOG — Phase 2 build (2026-08-19):
  - Branch phase-2-scurve-baseline-import created FROM phase-1-document-parser-
    improvement (includes a1b17a9).
  - DISCREPANCY RESOLVED: the file's quarterly cumulative block (R89-92:
    6.5/20.5/48.2/97) is author-entered and contradicts the activity table
    (probe: R88 total row = exact per-column activity sums; 242,317.46 ÷
    32,588,531.43 = 0.74%, not the claimed 6.5%). The imported curve is the
    ACTIVITY-DERIVED one (self-consistent; final month forced 100%).
  - app/Services/SCurveImportService.php (NEW): sheet scan → findHeader (ITEM /
    % WEIGHT / Month 1..N; month spans auto-detected as [col, col+1] when month
    headers land every other column) → buildMonths. Month peso = SUM of a column
    pair — the client writes each month's peso TWICE (each cell = half the month;
    e.g. field office F=179,256 = 7469 × 24 cells), and the sample's own TOTAL
    row proves it: paired columns (I+J, K+L, ...) sum to the exact contract
    ₱32,588,531.43. (Initially used MAX, assuming a copy-paste duplicate — that
    UNDERCOUNTS duplicated months; reverted to SUM after verification.) Summary-
    block rows ("% ACCOMPLISHED" / cumulative / cost) skipped even when they carry
    values; TOTAL row captured for contract fallback. Cumulative % monotonic-
    clamped, capped at 100, final month with data forced to 100. Output months
    carry month_index + activities; dates derived CLIENT-SIDE.
  - Real-sample result: projectName/location correct, contract 32,588,531.43,
    weightSum 100, 12 months, 59 activities, warnings [] → M1 1.12 / M4 10.59 /
    M7 28.64 / M10 68 / M12 100 — matches the file's own quarterly block at
    quarter-ends (6.74≈6.5, 21.09≈20.46, 49.73≈48.24, 100≈97).
  - app/Http/Controllers/SCurveImportController.php (NEW): POST /s-curve/parse
    accepts multipart file OR document_id (Schedule-type stored doc, validated
    .xls/.xlsx) → { success, months, meta } / 422. Routed in BOTH role groups
    (routes/web.php; admin + staff). Route cache had to be cleared to register.
  - public/js/admin/projects/scurve-import-core.js (NEW, ESM): isSCurveCandidate
    / isSCurveExcel / addCalendarMonths / formatDateInput / normalizeSCurveMonths
    (planned_date = start + (N-1) months, clamped to end date; no-start fallback
    keeps "Month N" labels). Attaches to window.scurveImportCore.
  - public/js/admin/projects/scurve-import-ui.js (NEW): create-wizard button
    (#scurveImportBtn, hidden until a Schedule/curve-named xlsx/xls/pdf pending
    doc appears — guarded refreshSCurveImportButton() hook in document-manager.js;
    PDF → "upload the .xlsx" notice), and project-detail button
    (#scurveImportBtnDetail, shown when a stored Schedule .xlsx/.xls exists).
    Import sets window._baselineSetupMonths (+ openBaselineSetupModal) in the
    wizard, or __setupModalContext { mode:'save-immediately', existingMonths }
    + openSetupBaselineModal() on the detail page. Double-init guarded.
  - baseline-schedule-setup-modal.js: openSetupBaselineModal now preloads
    ctx.existingMonths in mode 1 (save-immediately) too, so the detail page can
    preload imported months.
  - Blades: import button in _create_project_modal.blade.php (route
    $routePrefix . '.s-curve.parse'), import button + core/UI script tags in
    baseline-schedule.blade.php (mode-1 preload), core script tag in the create
    modal.
  - Tests: tests/Feature/SCurveImportTest.php (6 tests: client-layout fixture
    workbook via PhpSpreadsheet, pair de-dup, summary skip, metadata, errors,
    endpoint 422); tests/js/scurve-import.test.mjs (9 tests: date offsets,
    clamping, no-start fallback, activity passthrough, candidates).
  - Verification: npm run test:js 28/28; composer test 719 passed / 2778
    assertions; view:cache compiles all blades.
  - COMMITTED + PUSHED as 7dca28b "Implement Phase 2: S-curve Excel baseline
    import" on phase-2-scurve-baseline-import (12 files, +1222/-6); pushed to
    origin/phase-2-scurve-baseline-import. Awaiting user review before merge to
    main (Rule 12). Scratch files (inspect_142.php, kernel_export_test.php,
    session-ses_*.md, sampledocs/) intentionally left uncommitted.

IMPLEMENTATION LOG — Phase 2 follow-ups + Phase 3 planning (2026-08-19):
  - af69c97 "Add calendar date pickers to autofill preview dates; log Phase 2
    commit" (pushed): the autofill preview modal's Start/End Date rows now
    render as native type="date" inputs (calendar picker, same as the create
    modal); extracted values are normalized to YYYY-MM-DD at render.
  - SAMPLE CORRECTION — PDF text layer: CONSCHEDSAVINGS.pdf was previously
    recorded as "chart-only, no text layer". WRONG. Verified via raw stream
    inspection: it is a 2-page A3 (1190×842) table with a REAL text layer
    (embedded CID fonts, ToUnicode CMap, /Tj text operators, cell-rectangle
    graphics). The table's extra columns overflow onto page 2 (matches the
    user's observation). PDF S-curve import IS feasible client-side via the
    already-loaded pdf.js.
  - New samples added by the user: sampledocs/Completed Project/ (CHS -
    Savings.pdf 2.4MB 12pp, Contract - CHS Savings.pdf, Quantity Sheet - CHS
    Savings.pdf) and sampledocs/Ongoing Project/ (Quantity Sheet
    (Continuation).pdf, Three-Storey Acad Bldg, Buhi Campus.pdf 4.4MB). Used
    for manual QA of Excel hardening and the PDF importer.
  - LIBRARY ASSESSMENT (user asked whether plugins/libs could improve the
    Excel + PDF plans): NO new dependencies. The stack already has the best
    tools: PhpSpreadsheet ^4.0 (Excel) and client-side pdf.js + tesseract.js
    (PDF). Excel hardening = use PhpSpreadsheet features we skip today
    (formula calculation via getCalculatedValue(), getMergeCells() header
    spans, setReadDataOnly / setReadEmptyCells perf options). Server-side PHP
    PDF parsers (smalot/pdfparser) lack layout/position data; poppler
    (pdftotext) needs a system binary not installed on this Windows stack;
    cloud document-AI (AWS Textract / Azure Document Intelligence / Google
    Document AI) = paid keys + sending client documents to a third party. All
    rejected for now.
  - FINALIZED PLAN (user decisions): Commit 1 = Excel hardening + surfacing
    import warnings in the setup modal (on phase-2-scurve-baseline-import);
    Commit 2 = PDF S-curve import as a SEPARATE commit on a NEW branch
    phase-3-pdf-scurve-import (branched from phase-2), best-effort parse with
    "please upload the .xlsx" fallback for unrecognized layouts.

  COMMIT 1 — Excel hardening + warnings (SCurveImportService + UI):
    - Widen header vocabulary: item /(item|no\.|code)/i; weight
      /(weight|share|gravity|distribution|percent)/i (made OPTIONAL — no
      longer a hard header requirement); description /(description|particulars|
      work\s*items|activity|scope)/i.
    - More month labels: "Month N", "M N", month names/abbrevs (JAN..DEC);
      bare numbers 1..12 only as a fallback when they form an ascending run
      of >=2.
    - Two-row split header: when no single row matches, merge two adjacent
      header rows per-column; data starts after the merged block.
    - Lenient TOTAL row: /^(grand\s*)?total/i, excluded from activity count.
    - Formulas: if a month/weight/contract cell is a "=" string, re-read with
      PhpSpreadsheet's calculation engine (getCalculatedValue()); use
      getMergeCells() for header spans; setReadEmptyCells(false) for perf.
    - Surface meta.warnings in the setup modal (amber note + warning toast).
    - New fixture tests: month-names header, split header, no-weight column,
      GRAND TOTAL row, formula cells, bare-number months.

  COMMIT 2 — PDF S-curve import (branch phase-3-pdf-scurve-import):
    - New client-side scurve-pdf-parser-core.js (ESM, unit-testable) + parser
      using the existing pdf.js loader: extract text items (str, x, y) per
      page; detect the S-curve header tokens; infer column x-boundaries;
      assemble rows by y-band; merge the 2-page column overflow; feed the
      existing normalizeSCurveMonths + setup-modal flow.
    - Best-effort fallback: unrecognized layout → "please upload the .xlsx"
      notice; tesseract.js OCR only as last resort for scanned S-curves.
    - Unit tests (synthetic item lists) + manual QA on CONSCHEDSAVINGS.pdf and
      the new Ongoing/Completed Project PDFs.

  IMPLEMENTED 2026-08-19 (COMMIT 1, pending user review → commit+push):
    - SCurveImportService rewritten header detection: best-match scoring across
      all rows AND adjacent-row pairs (split header); item/weight now optional
      (description + >=2 month columns are the only hard requirements);
      anchored item regex prevents the "NO. OF DAYS" false-positive codeCol;
      per-month span logic with uniformly-paired fallback so the last paired
      month keeps its second column (tail-fill convention preserved — real
      sample still parses to 1.12/6.74/21.09/49.73/100, contract 32,588,531.43,
      59 activities, warnings []); merged-cell spans from getMergeCells() when
      present; bare-number months only as an ascending run of >=2; month names
      mapped via exact full/3-letter match (no prefix-prefix false positives);
      formula cells resolved via getCalculatedValue() safety net when toArray
      leaves "=" strings; lenient /^(grand\s*)?total/i TOTAL row.
    - Note: PhpSpreadsheet 4.0 moved Coordinate to the Cell\ namespace
      (PhpOffice\PhpSpreadsheet\Cell\Coordinate) — fixture tests didn't hit it
      (no merges), the real sample did.
    - scurve-import-ui.js: warnings surface as an amber note under the import
      button + a warning toast; cleared at the start of each import attempt.
    - Tests: SCurveImportTest 6 → 12 (month-names, split header, no-weight,
      GRAND TOTAL, bare-number months, formula cells). npm run test:js 28/28;
      composer test 725 passed / 2804 assertions; view:cache compiles. Real
      sample curve unchanged.

  IMPLEMENTED 2026-08-20 (COMMIT 2 — PDF S-curve import, on branch
  phase-3-pdf-scurve-import, UNCOMMITTED — pending user review → commit+push):
    - CONFIRMED LAYOUT: CONSCHEDSAVINGS.pdf (2pp A3) is NOT a "Month 1..12"
      table. It is a WEEKLY schedule: a multi-row header block (day-index row
      "#1..#90", month-span labels DECEMBER/JANUARY/FEBRUARY/MARCH, column-label
      row NO./DESCRIPTION/UNIT/QUANTITY/UNIT PRICE/AMOUNT/%, and a date row
      "December 9, 2024"…), WEEK 1..12 columns, and rows for incremental %,
      CUMULATIVE % (strictly increasing, tops out at 100), peso amounts, and
      working days. Page 2 = column overflow (WEEKS 9-12 continue from WEEKS
      1-8). Month-end cumulative points: Dec 36.70 / Jan 68.85 / Feb 91.16 /
      Mar 100.00.
    - public/js/admin/projects/scurve-pdf-parser-core.js (NEW, ESM): pure core
      with two layout paths:
        * Monthly-column layout (Description + Month 1..N / M1..N / names / bare
          numbers): infer column x-boundaries from the header line, assemble
          rows by y-band, sum pesos per month, merge pages by column-overflow
          or repeated-header (row continuation), build cumulative % via
          buildMonthsFromRows (monotonic clamp, final month forced 100).
        * Weekly-schedule layout (parseWeeklySchedulePdf): detect the WEEK row,
          the month-span label row, and the cumulative-% row (%-tokens in strict
          ascending order, max >= 50); assign week columns to month spans using
          boundary tolerance (curX = b.x - 30, nextX = nextBoundary.x - 30) so a
          week centered under a month header (WEEK 12 @ x=329 under MARCH @
          x=330) still lands in the right month; month-end cumulative % = the
          cumulative value at the last week of each span; sequential month_index
          1..N in appearance order (Dec→1, Jan→2, …). Real PDF now parses to
          1:36.7 / 2:68.85 / 3:91.16 / 4:100.
    - public/js/admin/projects/scurve-pdf-parser.js (NEW, classic): exposes
      window.parseSCurvePdfFile(file) → Promise<{months, meta}|null> using the
      existing pdfjs-loader (window.loadPdfJs), 20-page cap.
    - scurve-import-ui.js: PDF import wired into BOTH entry points (create
      wizard + project detail). Excel regression FIXED: importPDFSCurve now
      returns the raw {months, meta} (same shape as the Excel endpoint) and the
      shared normalizeSCurveMonths(...) call was restored for BOTH paths (the
      earlier draft bypassed it for Excel → modal got months without planned_date
      / month_label). Fallback notice for unrecognized layouts: "Could not read
      the S-curve from this PDF layout. Please upload the Excel (.xlsx) S-curve
      file instead."
    - Blades: _create_project_modal.blade.php + baseline-schedule.blade.php load
      the PDF parser core + wrapper; the baseline-tab Import button now shows for
      pdf docs too, with data-doc-type / data-download-url (route
      {role}.documents.download) / data-document-name for detail-page PDFs.
    - Tests: tests/js/scurve-pdf-parser.test.mjs 10 → 11 (weekly-schedule layout
      with month-span boundaries + multi-page overflow + boundary tolerance).
      npm run test:js 39/39; composer test 725 passed / 2806 assertions;
      view:cache compiles. QA on CONSCHEDSAVINGS.pdf = 4 months.
    - KNOWN LIMITATION: scanned PDFs (Ongoing Project/Three-Storey Acad Bldg,
      Buhi Campus.pdf 18pp; Completed Project/CHS - Savings.pdf 12pp) have 0 text
      items → parse returns null → fallback notice. OCR is the planned last-resort.

  PHASE 3 FOLLOW-UP — Gemini OCR last-resort (PLANNED, not yet implemented):
    - User asked whether the Gemini API can read scanned S-curves the way the
      Gemini web app reads uploaded images/docs. YES: generateContent supports
      inline_data parts (base64 + mimeType), including application/pdf — and the
      app ALREADY calls Gemini server-side (AIExplanationService::callGemini,
      config/services.php: gemini.key = env('AI_API_KEY'), gemini.model =
      env('AI_MODEL', 'gemini-1.5-flash')).
    - Proposed design (server-side fallback, not yet built):
        * New app/Services/AI/GeminiSCurveOcrService.php: receive PDF bytes
          (uploaded file or stored document_id) → base64 → inline_data PDF part
          + prompt asking for JSON { months: [{ month_index, cumulative % }] } →
          parse JSON → validate (monotonic clamp, force last month 100) → return
          the same { months, meta } shape as the Excel/PDF parsers. Empty/missing
          key or failed call → null (existing fallback notice).
        * Endpoint: extend SCurveImportController (both role groups).
        * Client: scurve-import-ui.js tries free client-side pdf.js first; on
          null (unrecognized layout OR scanned PDF) → call server Gemini OCR;
          only if both fail → "upload the .xlsx" notice.
    - Open decisions (awaiting user): (1) fallback order pdf.js → Gemini → .xlsx
      OK? (2) whole PDF inline vs render pages to images client-side first for
      large scans? (3) server-side Gemini only, or also free tesseract.js client
      option in parallel? (4) part of Phase 3 or separate follow-up branch?
    - Tradeoffs logged: cost (per-call API), privacy (contractor docs leave the
      server — note the app already sends project data to Gemini for AI analysis,
      so the provider-privacy precedent exists), robustness (vision handles dense
      numeric tables better than tesseract), determinism (LLM output varies →
      needs validation/clamping).

Reference Documents:
- textfiles/active_reference/project_context_and_development_rules.txt
- textfiles/summary_of_next_work.txt
- textfiles/project_health_unification_plan.md (recent feature, same code area)

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name / Client / Framework
  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).

Frontend convention (IMPORTANT)
  App UI JS/CSS lives in `public/` linked via asset('...') — NO @vite for new
  features. Feature scripts: public/js/admin/projects/*.js, public/css/admin/
  projects/*.css. The only @vite usage is the legacy app.blade.php bootstrap.

Feature area
  The create-project wizard (admin/_create_project_modal.blade.php, shared by
  all roles via projects/index.blade.php + project_detail) is a 5-page form:
  Page 1 Documents → Page 2 Project Info → Page 3 Details → Page 4 Timeline
  (incl. Baseline Setup) → Page 5 Assignment.

  Baseline model: ProjectBaselineSchedule rows { month_label, planned_date,
  planned_progress_percent (CUMULATIVE 0-100), original_* frozen copies,
  description } + ProjectActivity rows { activity_name, description,
  month_label, is_completed } per month. Setup Baseline modal supports a
  "return-data" mode (baseline-schedule-setup-modal.js:38-43) that accepts a
  pre-built months array (with per-month activities) for review before locking.

===============================================================================
  2. STATUS SUMMARY
===============================================================================

Phase 1 — PDF text extraction & autofill (document-parser.js)
  The feature works for simple text PDFs but has correctness bugs and gaps:
  1. Words are glued together — extractTextFromPDF() (document-parser.js:169)
     appends item.str with no space insertion; PDF.js emits one item per word,
     so "Construction of Building" often becomes "ConstructionofBuilding" and
     every regex below fails. THE #1 BUG.
  2. Multi-column/table PDFs scrambled — line grouping only uses Y position
     (lines 166-170); two columns interleave. Need Y-bucket + X-sort + spacing.
  3. clearAutofillFields() (lines 384-409) wipes ALL 7 fields unconditionally,
     INCLUDING values the user typed manually (contradicts its own comment).
     Called on every Page1→2 run and on Page2→1 back-nav.
  4. Budget false-positives — the fallback /(?:php|₱)\s*([\d,...])/i (line 288)
     matches ANY amount anywhere in the doc (page numbers, other table totals).
  5. Table-style docs unsupported — all extractors require label+value on the
     SAME line; common contracts/BOQs put the value on the NEXT line.
  6. Scanned/image-only PDFs unsupported — empty text → warning only, no OCR.
  7. No page cap — a 500-page spec is parsed page-by-page before filling.
  8. nextPage() awaits the whole autofill run (form-navigation.js:122) even
     though navigation already happened (minor; background run is better).
  9. Single CDN for PDF.js with a 3s wait-then-fail, no fallback.
  10. No tests (no JS test infra in package.json).

  APPROVED SCOPE (user decisions, 2026-08-19):
  - "Everything": core accuracy fixes + OCR + preview-then-apply UI.
  - Remove the success banner ("N field(s) pre-filled from your uploaded
    documents. Please review...") — the per-field indicators under each input
    are enough. Scanning/warning banners stay.
  - On Next: if fields are found, show a PREVIEW modal instead of instantly
    filling; Apply fills via existing autofillFormFields + indicators.

Phase 2 — S-Curve baseline auto-import (NEXT implementation)
  Fully planned below. User decisions (2026-08-19):
  - Entry points: BOTH create-wizard (Page 1 uploads) AND project detail
    Baseline tab (stored "Schedule" docs).
  - Excel parsed SERVER-SIDE via PhpSpreadsheet (already in composer.json) —
    no new dependency.
  - Client's S-curve files are ACTIVITY-WEIGHTED (each activity has a weight %),
    not just cumulative; a mix of layouts is possible and the actual files are
    not yet fully understood. Schema decision: OPTION A (schema-light) — weights
    are used at import time to derive cumulative month %, project_activities is
    NOT extended. No migration.

===============================================================================
  3. PHASE 1 — DOCUMENT PARSER IMPROVEMENT  (PRIORITY)
===============================================================================

Goal
  Make PDF autofill trustworthy: correct text reconstruction, non-destructive
  to manual input, accurate field extraction for both prose and table/contract
  layouts, an OCR fallback for scans, and a preview-before-apply flow.

Target files
  - public/js/admin/projects/document-parser.js (primary; split + rewrite)
  - public/js/admin/projects/form-navigation.js (await + preview hook)
  - public/js/vendor/pdfjs-loader.js (NEW — CDN fallback loader)
  - public/js/admin/projects/document-parser-core.js (NEW — pure, testable)
  - tests/js/document-parser-core.test.mjs (NEW — node --test, zero deps)
  - resources/views/components/autofill-preview-modal.blade.php (NEW)
  - resources/views/admin/_create_project_modal.blade.php (add button + modal)
  - package.json (add "test:js" script)
  - public/css/admin/projects/_create_project_modal.css (preview modal styles)

3.1 Core extraction rewrite (extractTextFromPDF)
  - Group PDF.js textContent items into LINES by Y-position tolerance, sort each
    line by X-position, join words with a space when the X-gap exceeds a small
    threshold (and honor item.hasEOL) — fixes glued words AND column interleave.
  - Page cap (first ~50 pages) with early-exit once every field has a value.

3.2 Autofill tracking (non-destructive to manual input)
  - Maintain a `_autofilledFields` Set. setIfEmpty() records a field only when
    autofill actually writes it. clearAutofillFields() clears ONLY tracked
    fields and empties the Set. User-typed values survive back-nav and re-runs.

3.3 Smarter parsing (parseProjectInfo + helpers)
  - Add line-based findLabeledValue(): find the label line; take the value on
    the same line, else the NEXT non-empty line. Used by title/location/
    start/end/duration/contractor/budget. Handles contract/BOQ/table layouts.
  - Budget: label-anchored only, tolerate "(ABC)" variants, REMOVE the ambient
    php|₱ catch-all (kills page-number/total false positives).
  - Dates: keep parseToInputDate; sanity-check start < end; handle label-then-
    value-on-next-line.
  - Duration: support "240 days", "8 months", "1 year" → days.
  - Keep the run-id supersede guard.

3.4 OCR fallback for scanned PDFs (lazy, CDN)
  - When PDF.js yields little/no text for a page, render that page to a canvas
    and OCR with tesseract.js (v5 UMD, lazy-injected <script> from jsdelivr,
    'eng'). Cap at first ~10 textless pages. Show "Scanning… (OCR)" banner
    state. OCR text joins the same parse pipeline.

3.5 Preview-then-apply UI
  - New component modal autofill-preview-modal: lists each field → extracted
    value or "not found", with Apply / Cancel buttons.
  - Page 1 gets a "Preview Extracted Data" button that runs the pipeline
    without navigating.
  - On Next: if fields were found, open the preview modal (do NOT fill yet);
    Apply calls autofillFormFields (per-field indicators show under inputs).
  - REMOVE showAutofillSuccess() / the success banner entirely.

3.6 Robustness
  - New pdfjs-loader.js: try jsdelivr → cdnjs fallback, then fail with a clear
    console warning (replaces the single-CDN 3s timeout).

3.7 Tests (zero-dependency, Node built-in)
  - Move pure logic (line grouping, spacing, findLabeledValue, all extractors,
    parseToInputDate, toTitleCase) into document-parser-core.js with BOTH a
    global (window/documentParserCore) and module export.
  - tests/js/document-parser-core.test.mjs uses node:test; fixtures include a
    mock multi-column page, a table-style contract, and date/budget edge cases.
  - npm script: "test:js": "node --test tests/js".
  - Verification: npm run test:js + full composer test (PHP untouched → still
    713 passed) + manual QA: text PDF, scanned PDF, back-nav preserves manual
    input.

===============================================================================
  4. PHASE 2 — S-CURVE BASELINE AUTO-IMPORT  (NEXT, after Phase 1)
===============================================================================

Goal
  Turn an uploaded planned S-curve (Excel or PDF) into baseline months — and,
  because the client's files are activity-weighted, import the per-month
  activities too — then pre-load them into the existing Set Up Baseline modal
  for review/editing before the user locks the baseline.

Data target (existing, no schema change — Option A)
  - Baseline months: { month_label: 'Y-m', planned_date, planned_progress_percent
    (cumulative 0-100), description } — what the Setup Baseline modal already
    consumes in return-data mode.
  - Per-month activities: { activity_name, description } on the month object.
  - project_activities is NOT extended (weights used only at import time).

STEP 0 — Sample files (HARD DEPENDENCY) ✅ DONE (2026-08-19)
  Real client files uploaded to `sampledocs/`. Findings:
    Excel — `Updated-S-Curve-3-Storey-Bldg.CSPC-Buhi.xlsx`
      Single sheet "S-CURVE". LAYOUT B (activity-weighted).
      - Rows 1-8: metadata (contract no "2026 -INFRA- 01", project name, agency,
        location; R6 "Contract Name:" etc.)
      - R9: title "CONSTRUCTION SCHEDULE AND S-CURVE"
      - R10-11: TWO-ROW header — A=ITEM, C=DESCRIPTION, D=AMOUNT, E=3% DISCOUNT,
        F=CONTRACT AMOUNT, G=% WEIGHT, H=NO. OF DAYS, then "Month 1..12" at
        columns I, K, M, O, Q, S, U, W, Y, AA, AC, AE (R11 has day markers).
      - KEY: each month = 2 ADJACENT columns (I:J=Month 1, K:L=Month 2, ...,
        AE:AF=Month 12); the value is duplicated in both cells of a pair.
        Verified: activity A.1.1(3) F=179,256 ÷ 24 cells = 7,469 exactly →
        each activity spreads its CONTRACT AMOUNT evenly across its months.
        Per-month amount = SUM of the pair (I+J, K+L, ...).
      - Activity rows: B=item code, C=description, G=% WEIGHT (e.g. 0.55 =
        0.55%; TOTAL row R88 G=100 confirms activities sum to 100), H=days,
        monthly peso amounts in the pair columns.
      - Section-header rows (PART A-H, DIVISION I-II) have NO weight → skip.
      - R88 TOTAL row (C="TOTAL", F=contract total 32,588,531.43, G=100) +
        R89-92 S-curve summary block ("% ACCOMPLISHED", "CUMULATIVE %
        ACCOMPLISHED" fractions, "COST", "CUMULATIVE COST") at Months
        1/4/7/10 → cumulative = 6.5% / 20.5% / 48.2% / 97% (cross-check target).
      - Quirks: stray backtick at AH (R27), whitespace-only cells, weights as
        plain % numbers, NO real dates (only "Month 1..12").
    PDF — `CONSCHEDSAVINGS.pdf`
      2 pages, "Microsoft: Print To PDF", NO extractable text layer (embedded
      fonts only) → CHART-ONLY. Confirms the PDF path needs the "chart-only →
      upload the .xlsx / manual fallback" message. Other PDFs (Contract, Quantity
      Sheets, plans in Completed/Ongoing folders) are Phase 1 autofill test
      material, NOT S-curve sources.

  SCOPE DECISION (2026-08-19): implement EXCEL import first (server + UI +
  tests). Client-side PDF text-layout importer DEFERRED (sample PDF has no text
  layer anyway); PDF S-curve files get a clear "Excel import is supported —
  please upload the .xlsx version" notice. No schema change (Option A).

  MONTH DATE DECISION (2026-08-19): the workbook has no dates. planned_date for
  month N = project's planned start date + (N-1) months, clamped to the project
  end date. If no start date is set, import with "Month N" labels + null dates
  for the user to fill in the modal. Dates are assigned CLIENT-SIDE (server
  returns month_index 1..12 — keeps the service pure/testable).

  BRANCH DECISION (2026-08-19): branch Phase 2 from the Phase 1 branch
  (`phase-2-scurve-baseline-import`) so the PDF path can reuse the Phase 1 core.

STEP 1 — Flexible parser core
  CONFIRMED for the client file: Layout B (activity-weighted, "CONSTRUCTION
  SCHEDULE AND S-CURVE"). Shared heuristics (used by PHP for Excel and later by
  JS for PDF text):
  - Find the header block: row containing ITEM + % WEIGHT + Month 1..12 labels.
    Month columns are PAIRED (I:J=Month 1 ... AE:AF=Month 12); sum each pair.
  - Skip: section-header rows (PART A-H / DIVISION I-II — no weight), TOTAL row
    (C="TOTAL", G=100 — use its F as the contract total), the summary block
    ("% ACCOMPLISHED" / "CUMULATIVE % ACCOMPLISHED" / "COST"), and garbage
    cells (stray backtick, whitespace-only).
  - Per activity: code, description, weight %, and each month's peso sum.
  - Month % of total = Σ activity month-peso ÷ contract total × 100.
  - Emit months 1..12: cumulative %, monotonic clamp, force last month to 100%;
    description = that month's activity names (Layout B).
  - Cross-check: weight sum ≈ 100 (else warning); compare against the file's
    CUMULATIVE % block when present (quarterly 6.5/20.5/48.2/97 in the sample).
  - Mismatch warnings surfaced in the preview, never silent.

STEP 2 — Excel import path (SERVER)
  - NEW app/Services/SCurveImportService.php — parse(string $path): array via
    PhpSpreadsheet, implementing the heuristics above. Pure (no dates — emits
    month_index 1..12).
  - NEW app/Http/Controllers/SCurveImportController.php — parseExcel(Request)
    accepting either multipart `file` (create wizard) OR `document_id` (project
    detail → reads the stored Schedule-type Document bytes from storage).
    Returns { success, months, meta } or 422 { errors, warnings }.
  - Routes (role-protected): POST /admin/s-curve/parse + /staff/s-curve/parse.
  - PHP fully unit-testable: fixture workbooks built with PhpSpreadsheet in-test.

STEP 3 — PDF import path (CLIENT) — DEFERRED (per scope decision)
  - Reuse Phase 1's extractTextFromPDF() + OCR; feed text into the same parser
    heuristics (§1) re-implemented client-side.
  - Chart-only PDFs (no text layer — e.g. the client's CONSCHEDSAVINGS.pdf) →
    clear "cannot import chart-only PDF — please upload the .xlsx" message.
  - THIS SCOPE: show that message only; no PDF text parsing yet.

STEP 4 — UI wiring (BOTH entry points)
  - NEW public/js/admin/projects/scurve-import-ui.js:
    - normalizeMonths(months, startDate, endDate) — month_label 'Y-m' and
      planned_date = start + (N-1) months, clamped to end; no start date →
      'Month N' label + null date.
    - importFromFile(file) / importFromDocumentId(id) → endpoint → normalize.
  - Create wizard Page 4 baseline section (return-data mode): "Import from
    S-curve document" button shown when a pending doc has typeName='Schedule'
    AND ext .xlsx/.xls (re-evaluated on doc add/remove — guarded hook in
    document-manager.js). Click → import → window._baselineSetupMonths
    (confirm if non-empty) → updateBaselineSetupButton() → open modal.
    PDF S-curve pending → "upload the .xlsx" notice.
  - Project detail Baseline tab (save-immediately mode): same button when the
    project has a stored Schedule .xlsx/.xls → document_id → preload months
    into the modal for review.
  - baseline-schedule-setup-modal.js: preload existingMonths in mode 1 too
    (currently only return-data preloads) so project-detail can preload.
  - Non-blocking: if import fails, modal still opens empty with a notice.

STEP 5 — Tests
  - PHP feature tests (tests/Feature/SCurveImportTest.php): fixture workbook
    built via PhpSpreadsheet replicating the client layout (two-row header,
    section rows, weighted activities with month pairs, TOTAL row, quarterly
    cumulative block); assert month cumulative %, activity descriptions, 100%
    enforcement, warnings; garbage workbook → 422; document_id path.
  - node --test (tests/js/scurve-import.test.mjs): normalizeMonths date
    offsets, clamping, no-start-date fallback.
  - Full composer test stays green; manual QA against the real client Excel
    (compare cumulative 6.5/20.5/48.2/97).

===============================================================================
  5. ORDER & PRIORITY
===============================================================================

  PHASE 1 FIRST: improves the existing autofill, no dependency on Phase 2.
  PHASE 2 AFTER: builds on Phase 1's parser core + OCR. USER DECISION: branch
  Phase 2 from the Phase 1 branch (`phase-2-scurve-baseline-import`), not main.
  Both merge to main only with explicit approval (Rule 12).

  Per dev rules: each phase on its own branch; run lint/tests; present to the
  user for review before merging (Rule 12).

===============================================================================
  PHASE 4 — DOCUMENT VIEWER ENHANCEMENT + EXTENSION ATTACHMENTS FIX
===============================================================================

Date:     August 21, 2026
Branch:   TBD (Rule 12 — branch on explicit go-ahead)
Status:   PLANNED (not yet implemented)

---------------------------------------------------------------------------
  1. PROBLEMS
---------------------------------------------------------------------------

  A. Extension attachments open in new browser tab (403) instead of the
     preview modal used by documents, issues, progress, and risk attachments.

     Root cause: baseline-schedule-extensions.js:120-127 and
     review_extension_modal.blade.php:185-191 render evidence as
     <a href="${a.file_url}" target="_blank"> — using the raw storage URL
     (/storage/...) which is broken (public/storage is an empty directory,
     not a symlink) AND not auth-protected.

     Contrast: issues-modal.js, progress-attachments.js, risk-register.js
     all correctly use openDocumentViewer() with role-scoped viewUrl
     (/{role}/attachments/{id}/view).

  B. Images (PNG/JPG) show broken icon in the viewer modal iframe.

     The modal's iframe loads the viewUrl which returns the file via
     Storage::disk('public')->response() (BinaryFileResponse with
     Content-Disposition: inline). PDFs render because browsers have built-in
     PDF viewers; images may fail if BinaryFileResponse's Content-Type
     detection is wrong or the response is misformatted.

     Note: public/storage is an empty directory (not a symlink), but the
     view routes read directly from storage/app/public/ on the filesystem,
     so this alone shouldn't break the view endpoint.

  C. Excel files (xls/xlsx) show "Preview Not Available" fallback.

     Not in the PREVIEWABLE list, and even if they were, an iframe cannot
     render binary xlsx content.

  D. DOCX files show fallback.

     No server-side renderer installed; browser cannot render docx natively.

---------------------------------------------------------------------------
  2. SOLUTION OVERVIEW
---------------------------------------------------------------------------

  A. Extension attachments — follow the issues/progress/risk pattern:
     cache attachments in a window-level map, render clickable elements
     that call openDocumentViewer() with role-scoped viewUrl/downloadUrl.

  B. Images — render via <img> element instead of iframe. Browsers auto-
     detect Content-Type for <img> src, which is more reliable than
     BinaryFileResponse in an iframe context.

  C. Excel — new server-side preview endpoint using PhpSpreadsheet's
     Html writer. Returns an HTML table rendered in the iframe.

  D. DOCX — client-side mammoth.js (CDN, matches pdfjs-loader pattern).
     Fetch file as ArrayBuffer, convert to HTML, render in a div.

---------------------------------------------------------------------------
  3. IMPLEMENTATION STEPS
---------------------------------------------------------------------------

  STEP 1 — Fix storage symlink
    php artisan storage:link
    (On Windows may need admin or Developer Mode; fallback: mklink /J)

  STEP 2 — Fix extension attachments (2 files)
    File A: public/js/admin/projects/baseline-schedule-extensions.js
      - At ~line 120: replace <a href="${a.file_url}" target="_blank">
        with clickable <span> elements that call openDocumentViewer()
      - Cache attachments in window._extensionAttachments map
      - Add openExtensionAttachmentById(id) function
      - Build viewUrl: /${window.userRole}/attachments/${att.id}/view
      - Build downloadUrl: /${window.userRole}/attachments/${att.id}/download
      - Use attachment-chip styling consistent with other tabs

    File B: resources/views/projects/project_detail/components/modals/
            review_extension_modal.blade.php
      - At ~line 185: same fix — replace <a target="_blank"> with
        openDocumentViewer() calls

  STEP 3 — Fix image preview in the modal
    File: resources/views/components/document-viewer-modal.blade.php
      - Add new container elements (#dvImageContainer for <img>,
        #dvHtmlContainer for DOCX HTML)
      - Modify openDocumentViewer() viewer strategy:
          image exts  → <img> element with viewUrl as src
          pdf         → iframe (existing)
          xls/xlsx    → iframe with previewHtml endpoint URL
          docx        → fetch + mammoth.js → render in #dvHtmlContainer
          else        → fallback
      - Clean up containers on modal hide (alongside existing iframe cleanup)

  STEP 4 — Add Excel preview endpoint
    File: app/Http/Controllers/DocumentController.php
      - Add previewHtml($documentId) method
      - Reuse DocumentPolicy::view auth + fileExists() check
      - For xls/xlsx: IOFactory::load() → Html writer → generateHtmlAll()
      - Return response($html)->header('Content-Type', 'text/html; charset=utf-8')

    File: app/Http/Controllers/AttachmentController.php
      - Add same previewHtml() method

    File: routes/web.php
      - Add in all 3 role groups:
        Route::get('/documents/{document}/preview', ...)->name('{role}.documents.preview');
        Route::get('/attachments/{attachment}/preview', ...)->name('{role}.attachments.preview');

  STEP 5 — Add DOCX preview (client-side)
    File: public/js/vendor/mammoth.browser.min.js (NEW)
      - Download mammoth.js UMD bundle from CDN
      - Follows the same pattern as pdfjs-loader.js

    File: resources/views/components/document-viewer-modal.blade.php
      - Add lazy CDN loader for mammoth.js (jsdelivr/unpkg fallback)
      - When DOCX opened: fetch file as ArrayBuffer, call
        mammoth.convertToHtml({arrayBuffer}), render HTML

  STEP 6 — Pass previewUrl from Blade templates
    File: documents.blade.php
      - Add $previewUrl = route($role . '.documents.preview', $document->id)
      - Pass previewUrl in openDocumentViewer() calls

    Files: progress.blade.php, baseline-schedule.blade.php,
           issues-modal.js, risk-register.js,
           baseline-schedule-extensions.js
      - Add previewUrl to their openDocumentViewer() calls

  STEP 7 — Update plan MD + run tests
    - Update this plan with implementation log (Rule 11)
    - Run composer test + npm run test:js to verify no regressions

---------------------------------------------------------------------------
  4. FILES TO MODIFY
---------------------------------------------------------------------------

  resources/views/components/document-viewer-modal.blade.php
    Multi-type viewer (img/excel-html/docx/html), mammoth CDN loader

  public/js/admin/projects/baseline-schedule-extensions.js
    Replace <a target="_blank"> with openDocumentViewer() calls

  review_extension_modal.blade.php
    Same — replace <a target="_blank"> with openDocumentViewer()

  app/Http/Controllers/DocumentController.php
    Add previewHtml() method

  app/Http/Controllers/AttachmentController.php
    Add previewHtml() method

  routes/web.php
    Add preview routes for all 3 role groups

  documents.blade.php
    Pass previewUrl

  progress.blade.php
    Pass previewUrl

  baseline-schedule.blade.php
    Pass previewUrl

  public/js/admin/projects/issues-modal.js
    Pass previewUrl

  public/js/admin/projects/risk-register.js
    Pass previewUrl

  public/js/vendor/mammoth.browser.min.js (NEW)
    mammoth.js CDN bundle for DOCX

---------------------------------------------------------------------------
  5. OUT OF SCOPE
---------------------------------------------------------------------------

  - PPT/PPTX preview: no reliable server-side or lightweight client-side
    renderer; keep as download-only fallback.
  - Word .doc (legacy binary format): mammoth.js only supports .docx;
    .doc falls to fallback.

===============================================================================
  END OF PLAN
===============================================================================
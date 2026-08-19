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
        ✅ Phase 2 — PLANNED with real client samples analyzed + decisions locked
        (implementation pending on its own branch, Rule 12)
Date:   August 19, 2026
Branch: Phase 1 on `phase-1-document-parser-improvement`; Phase 2 will branch
        FROM the Phase 1 branch (user decision) as `phase-2-scurve-baseline-import`.

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
  END OF PLAN
===============================================================================
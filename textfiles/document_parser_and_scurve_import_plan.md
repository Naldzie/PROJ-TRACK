# PDF DOCUMENT PARSER IMPROVEMENT + S-CURVE BASELINE AUTO-IMPORT

CSPC PMU System — two related implementations on the create-project wizard's
document uploads:

- **PHASE 1 (PRIORITY — do this FIRST):** fix and extend the PDF text-extraction /
  autofill feature (`document-parser.js`) that reads project info out of uploaded
  PDFs and pre-fills the create-project form.
- **PHASE 2 (NEXT, after Phase 1 lands):** automate baseline setup by importing a
  planned S-curve (Excel or PDF) into the existing "Set Up Baseline" modal.

Status: ⬜ Phase 1 — PLANNED (not started) · ⬜ Phase 2 — PLANNED (not started)
Date:   August 19, 2026
Branch: (Phase 1 on the current work branch, then merge before Phase 2)

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

STEP 0 — Sample files (HARD DEPENDENCY)
  Get 1-2 real S-curve files from the client/contractor (Excel + PDF) into the
  repo as fixtures BEFORE writing heuristics. Layouts expected (mix possible):
    A) Cumulative table: period column (Date/Month) + "Planned % / Cumulative %
       / S-Curve / Target %" column; possibly a ₱ value column (convert value ÷
       total × 100).
    B) Activity-weighted schedule: rows of activity name | start date | end date
       (or duration) | weight %. Cumulative % per month = running sum of weights
       of activities scheduled up to that month.
  Files are also submitted by the contractor to the client's office, so real
  samples will reflect contractor conventions.

STEP 1 — Flexible parser core (client JS, extends Phase 1 core)
  - Detect layout A or B from the header row / first rows.
  - Layout A → period + planned-% columns (auto-detect ₱ column → convert).
  - Layout B → activity rows; assign each activity to its month(s); derive each
    month's cumulative % from summed weights.
  - Emit months sorted by date; monotonic clamp; force last month to 100%;
    description = activity names for the month (layout B).
  - Mismatch warnings: row count vs project duration, missing 100%, non-
    monotonic %, unknown columns → surfaced in the preview, never silent.

STEP 2 — Excel import path (SERVER)
  - New endpoint POST parse-s-curve (role-protected admin/staff) accepting an
    Excel file (multipart) → JSON { months: [...] }.
  - Parse with phpoffice/phpspreadsheet (already required): iterate rows,
    detect header, run the same layout-A/B heuristics.
  - Use from BOTH entry points: create-wizard AJAX-uploads the in-memory File;
    project-detail POSTs the stored file's bytes.
  - PHP = fully unit-testable (feature tests build fixture workbooks with
    PhpSpreadsheet in-test).

STEP 3 — PDF import path (CLIENT)
  - Reuse Phase 1's extractTextFromPDF() + OCR; feed text into the same parser.
  - Chart-only PDFs (no data table) → clear "cannot import chart-only PDF"
    message + manual fallback.

STEP 4 — UI wiring (BOTH entry points)
  - Create wizard Page 4 "Set Up Baseline": if an S-curve PDF/Excel was picked
    on Page 1, show "Import from S-curve document".
  - Project detail Baseline tab: same button when a "Schedule"-type doc exists.
  - Imported months (+activities) pre-load the existing Setup Baseline modal
    (return-data mode) for review/edit before "Done — Lock Baseline".
  - Non-blocking: if import fails, modal still opens empty with a notice.

STEP 5 — Tests
  - PHP feature tests for parse-s-curve: fixture xlsx (cumulative table AND
    activity-weighted) generated via PhpSpreadsheet; assert month rows, clamp,
    100% enforcement, error JSON on garbage input.
  - node --test fixtures for the PDF/table parser paths (Phase 1 core).
  - Full composer test stays green; manual QA against the real client files.

===============================================================================
  5. ORDER & PRIORITY
===============================================================================

  PHASE 1 FIRST (this session's focus): improves the existing autofill, no
  dependency on Phase 2. Merge Phase 1 to main before starting Phase 2.
  PHASE 2 AFTER: builds on Phase 1's parser core + OCR; its own branch from
  main after Phase 1 is merged.

  Per dev rules: each phase on its own branch; run lint/tests; present to the
  user for review before merging (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================
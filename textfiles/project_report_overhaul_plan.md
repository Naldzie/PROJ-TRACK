# PROJECT REPORT OVERHAUL — PER-PROJECT REPORT AS AN EDITABLE WORD DOCUMENT
# Parent refs: textfiles/report_generation_plan.md (the Aug 2026 R1-R3 build: what the
#                current PDF is, why dompdf, the Excel "corruption" freeze §6.4, FE-1..FE-3)
#              textfiles/ai_analysis_recommendation_merge_plan.md §8 (the two parked PDF
#                defects: raw [SECTION:] markers + the UTF-8 BOM)
#              textfiles/active_reference/project_context_and_development_rules.txt
# Date: September 21, 2026
# Status: RESTUDY DONE. P0 (BOM fix) BUILT on phase-38-remove-utf8-bom — the user is
#         checking whether it also fixes the Excel "corruption". Decisions R-D1, R-D2,
#         R-D4..R-D8 answered 2026-09-21 (§6); R-D3 explained, answer pending.
# Branch on build: phase-39-project-report-overhaul (Rule 12) — phase-38 went to the
#         BOM fix, which ships on its own.

===============================================================================
  0. HANDOFF — READ THIS FIRST (cold session)
===============================================================================

  THE ASK (user, 2026-09-21)
    "Overhaul the per-project PDF summary report completely — especially WHAT CONTENT
    should be on it. Our consultant recommends making it a WORD document so users can
    still edit it. The current PDF is just a first sample implementation; I want
    something better. Restudy first, for better planning."

  THE THREE FINDINGS THAT SHAPE THE PLAN
    1. ⚠ THE BOM BREAKS EVERY DOWNLOADED FILE — AND IT IS VERY LIKELY THE "EXCEL
       CORRUPTION" THAT FROZE THE EXCEL EXPORTS IN AUGUST. bootstrap/app.php is saved
       with a UTF-8 BOM, so every HTTP response starts with EF BB BF. Measured:
       ~/Downloads/prj-2026-00050_progress.xlsx starts "ef bb bf 50" (BOM, then the
       zip's "P"); all 7 downloaded project-summary PDFs start with it too. An .xlsx /
       .docx is a ZIP — 3 junk bytes in front and Office says "corrupt / repair?".
       The tests never saw it because they capture the stream body directly, not
       through the HTTP kernel. A Word report would be hit exactly the same way, so
       the BOM fix is STEP 0 of this plan, not a later PDF chore.
    2. THE VIEWER CAN ALREADY PREVIEW .DOCX. components/document-viewer-modal already
       routes *.docx through Mammoth.js (lazy CDN, jsDelivr→unpkg). So "view before
       download" survives a move to Word with no new viewer. Mammoth's limits decide
       some design choices: it shows headings, paragraphs, tables, bold and EMBEDDED
       IMAGES; it DROPS page headers/footers, page layout and NATIVE Word charts.
    3. EVERYTHING NEEDED IS ALREADY INSTALLED EXCEPT THE WORD WRITER ITSELF.
       phpoffice/phpword 1.4.0 (LGPL-3.0, 2025-06) needs dom, gd, zip, json, xml —
       all present on this PHP 8.2.12. GD has FreeType, and dompdf ships
       DejaVuSans(-Bold).ttf, so a server-drawn S-curve PNG is feasible.
       No LibreOffice on this machine (so no DOCX→PDF conversion server-side).

===============================================================================
  1. WHAT EXISTS TODAY (traced in source 2026-09-21)
===============================================================================

  1.1 THE PIPELINE
    Header "Reports" dropdown (components/header.blade.php:156-184)
      → openDocumentViewer({viewUrl: …exports.summary.view, downloadUrl: …exports.summary})
    routes/web.php — per role (staff :385, contractor :501, admin :894):
      GET /projects/{project}/reports/summary.pdf   → ReportExportController::summaryPdf
      GET /projects/{project}/reports/summary/view  → ::summaryPdfView (inline, no audit)
    ReportExportService::buildSummaryDompdf()
      data  = ProjectDetailService::prepareProjectData() + prepareChartData()
      chart = buildSvgChart() — hand-built SVG, base64 <img> (dompdf has no inline SVG)
      html  = view('reports.project-summary') → Dompdf A4 → streamed
    Authorization inside the service: admin/staff any project, contractor own only.
    ActivityLog "Export Report" on download only.

  1.2 WHAT THE CURRENT PDF CONTAINS (reports/project-summary.blade.php, 303 lines)
    header (title, code, generated-at) · 9-cell meta grid (status, category, location,
    progress, approved budget, contractor, start, planned end, extension COUNT) ·
    S-curve · LATEST inspection only (1 row) · open issues (name/type/severity/status)
    · extension history · AI analysis (printed RAW, markers and all) · AI
    recommendations table · footer.

  1.3 WHAT IS WRONG WITH IT AS A REPORT
    · No reporting period. It is "as of whenever you clicked", not "Report for
      September 2026". A PMU report is periodic (monthly inspection cycle).
    · No executive summary — the reader must derive planned-vs-actual, slippage and
      time elapsed from separate places. Health / risk / forecast (all computed by
      AIPredictionPresenter) are absent.
    · Only the latest inspection; no monthly progress history (FE-2 asked for it).
    · Nothing on WORK ACCOMPLISHED — the monthly activity checklist (project_activities,
      is_completed per month) and carryovers are the PMU's own tracking and are unused.
    · Issues: open only, no description / action taken / resolution.
    · Contract facts missing: ABC, budget source, contract duration, original vs
      revised completion date, actual start, project engineer/manager, handover date,
      scope/description (all columns exist on `projects`).
    · AI analysis printed raw ([SECTION:Report] … [/SECTION]).
    · No signatories (Prepared / Checked / Noted / Approved) — a government report
      is signed; this is the main thing the PMU would EDIT in Word.
    · No page numbers, no letterhead.
    · Not editable (the consultant's point).

  1.4 DATA AVAILABLE FOR A RICHER REPORT (verified columns / services)
    projects: code, title, location, description, category, approved_budget, abc,
      budget_source, planned_start/end, original_planned_start/end, planned_duration,
      contract_duration_days, actual_start/end, actual_duration_days, extension_count,
      assigned_project_manager, project_manager_contact, estimated_workers, status,
      planned_handing_over_date, contractor (company, contractor_name, contact,
      address, performance_rating, on_time_delivery_pct, avg_delay_days …)
    progress history: month, inspection date, planned %, actual %, variance, remarks,
      recorded_by, status, edit trail; inspection attachments (context 'inspection' —
      photos are supported by the model, none uploaded yet in the live DB)
    project_activities: per month, activity_name, description, is_completed
      (+ project_activity_carryovers)
    baseline: month, planned date, planned %, original planned %/date (frozen plan)
    extensions: number, original/new/approved end, days, reason, reason_category,
      status, reviewed by/at
    issues: name, description, type, severity, status, linked month, resolution
      notes, resolved_at, attachments
    risks (optional feature — AGENTS.md: do NOT build on it until the client keeps it)
    AIPredictionPresenter::present(): status/health, risk_level, forecast_risk_level,
      current vs expected progress, progress_gap, days elapsed/total/remaining,
      estimated_completion, predicted_delay_days, completion_confidence, trend,
      next_prediction (+ range), reliability, class_standing …
    ai_solutions: analysis_text (5 sections — AiReportParser::parse() splits them),
      recommendations (category, priority, message, reason)
    ProjectChartService::prepareChartData(): frozen / adjusted planned + actual series
    documents: list by type (contract, supporting docs …)

===============================================================================
  2. PROPOSED CONTENT — "PROJECT MONITORING REPORT" (for review; §6 R-D1/R-D2)
===============================================================================

  Built around the PMU workflow the system already encodes: baseline → monthly
  inspection → compare against baseline → management decision → completion or
  extension. Each section says where its data comes from.

    HEADER / LETTERHEAD   CSPC · Project Management Unit · "Project Monitoring Report"
                          · Reporting period (e.g. "as of September 2026") · report
                          date · page X of Y in the footer (Word renders these).
    1. PROJECT INFORMATION  title, code, location, category, scope (description),
                          contractor (company, representative, contact), budget
                          source, ABC, contract amount, contract duration, start /
                          actual start, original completion, REVISED completion
                          (with extensions), handover date, project engineer, status.
    2. STATUS AT A GLANCE   planned % vs actual % as of the period, slippage (+/−),
                          time elapsed % vs work done %, days remaining, health status
                          + risk level (the same labels the dashboard shows), extensions
                          granted (count + total days). A short table, not prose.
    3. PROGRESS S-CURVE     original plan · adjusted plan (if extended) · actual —
                          embedded PNG image + the monthly table below it.
    4. MONTHLY PROGRESS     every inspection: month, inspection date, planned %,
                          actual %, slippage, remarks. (FE-2; "recorded by" omitted
                          per the August user note.)
    5. WORK ACCOMPLISHED    activities of the reporting month: done / not done;
                          activities carried over from earlier months.
    6. ISSUES & PROBLEMS    open issues AND issues resolved in the period: description,
                          severity, action taken / resolution notes, status.
    7. TIME EXTENSIONS      every extension: days, reason, status, approved date,
                          resulting completion date.
    8. FORECAST             plain-language: expected completion, likely delay, how
                          dependable the forecast is — from the presenter, not the AI.
    9. ANALYSIS             the 5 AI sections PARSED (AiReportParser) into headed
                          paragraphs, labelled "system-generated", with its date.
   10. RECOMMENDATIONS      category · priority · action · reason.
   11. PHOTO DOCUMENTATION  (optional) inspection photos of the period, with captions.
   12. SIGNATORIES          Prepared by / Checked by / Noted by / Approved by — name,
                          position, signature line. Pre-filled with the exporting user
                          for "Prepared by"; the rest left for the PMU to type in Word.

  Deliberately NOT in it: regression method/equation/R², reliability factor scores
  (the plain-language decision of 2026-09-21 applies to the report too); the risk
  register (optional feature, AGENTS.md); the documents list (unless asked).

===============================================================================
  3. ARCHITECTURE OPTIONS
===============================================================================

  3.1 ONE REPORT MODEL, SEPARATE RENDERERS (recommended, whatever the formats)
    ProjectReportDataService (new, read-only): builds ONE plain array — the report's
    content for a project + reporting period — from the existing services
    (ProjectDetailService, AIPredictionPresenter, AiReportParser, models). Renderers
    only lay it out. Keeps Rule 5 (math/services/renderers/controllers) and lets a
    PDF, if kept, never drift from the Word file.

  3.2 THE WORD WRITER
    A. phpoffice/phpword, document BUILT IN CODE (sections, tables, image, footer
       page numbers). Full control over dynamic sections (variable-length tables,
       optional sections). Styling lives in code.
    B. phpoffice/phpword TemplateProcessor on a .docx TEMPLATE the PMU can edit in Word
       (letterhead, fonts, signatory block), with ${placeholders}, cloneRow for tables
       and setImageValue for the chart. The PMU restyles without a developer; dynamic
       / optional sections are harder, and a broken placeholder breaks the report.
    C. Hybrid — built in code, but the letterhead + signatory names come from settings.
    Recommendation: A now (or C), B later only if the PMU wants to own the layout.

  3.3 THE S-CURVE IN WORD
    PNG drawn server-side with GD + DejaVu (same data + colours as buildSvgChart):
      + shows in Word, in LibreOffice, AND in the Mammoth preview; one image for both
        renderers. − not editable as a chart (the numbers are in section 4's table).
    Native Word chart (PhpWord Chart): + editable in Word. − invisible in the Mammoth
      preview; month-category x-axis only (no true dates / gaps), styling limited.
    Recommendation: PNG.

  3.4 PREVIEW + FORMATS
    · DOCX preview: the existing viewer's Mammoth path — headers/footers (page numbers,
      letterhead-in-header) will not show in the preview, only in Word. Needs internet
      for the CDN (already true for login, pilot F3).
    · Keep a PDF? No LibreOffice → no faithful DOCX→PDF on the server. Either keep a
      separate PDF renderer fed by the same report model (two layouts to maintain), or
      drop the PDF and let users "Save as PDF" from Word after editing — which is the
      consultant's workflow anyway (edit, then sign/print).

===============================================================================
  4. PREREQUISITES
===============================================================================

  P0  Remove the UTF-8 BOM from bootstrap/app.php (and resources/views/auth/
      login.blade.php, projects/project_detail/components/modals/issues_modal.blade.php).
      Then: add a feature test that fetches an export THROUGH THE HTTP KERNEL and
      asserts the first bytes (%PDF- / PK\x03\x04) — the gap that hid this for weeks.
      Re-download an .xlsx and open it in Excel: if it opens clean, the August
      "corruption" is explained and the Excel freeze (report_generation_plan §6.4)
      can be lifted — record that there.
  P1  composer require phpoffice/phpword:^1.4 — a new dependency (project rule: tell
      the user before adding one). LGPL-3.0; all PHP extensions already present.

===============================================================================
  5. PROPOSED BUILD ORDER (after the §6 decisions; each step tested — Rule 8)
===============================================================================

  0. P0 BOM fix + kernel-level byte test (own commit; also fixes the PDF/Excel today).
  1. ProjectReportDataService + unit tests (period selection, every section's data,
     empty-project behaviour, AI sections parsed, no technical terms in section 8).
  2. S-curve PNG renderer (GD) + test (valid PNG, size, non-flat lines).
  3. DOCX renderer (PhpWord) + routes (report.docx + inline view for the modal) +
     tests (valid zip, word/document.xml contains each section heading and key values,
     contractor authorization, ActivityLog on download only).
  4. Header dropdown: "Project Report (Word)" opening the viewer with a .docx name.
  5. PDF: per R-D4 — retire, or re-render from the report model.
  6. Browser check: preview in the modal, download, open in Word, edit, Save as PDF.

===============================================================================
  6. DECISIONS FOR THE USER
===============================================================================

  R-D1  CONTENT — is §2's section list right? What to add / drop?
  R-D2  REPORTING PERIOD — always "latest month", or a month picker (report for any
        past month, e.g. to re-issue August's report)?
  R-D3  WORD LAYOUT OWNERSHIP — built in code (A/C) or a PMU-editable Word template (B)?
  R-D4  PDF — drop it (users Save-as-PDF from Word), or keep a PDF alongside?
  R-D5  CONTRACTOR COPY — contractors can export today. Should their copy differ
        (e.g. no contractor-performance figures, no internal recommendations), or be
        the same document?
  R-D6  SIGNATORIES — which positions, and should names be pre-filled (settings) or
        left blank for typing in Word?
  R-D7  PHOTOS — include inspection photos when uploaded?
  R-D8  THE PMU'S OWN FORMAT — does the PMU (or the consultant) already use a report
        form (e.g. a monthly accomplishment / project status report)? If yes, a copy
        should drive §2 — sampledocs/ holds only contracts, quantity sheets and
        S-curves, no report form.

  ✅ ANSWERS (user, 2026-09-21)
  R-D1  CONTENT — §2 as proposed ("lets go with that"). Sections 11 (photos) and 12
        (signatories) change per R-D7 / R-D6 below.
  R-D2  PERIOD — BOTH: a month picker, defaulting to the latest month.
  R-D3  LAYOUT — user asked for an explanation + recommendation first. PENDING.
  R-D4  FORMATS — BOTH: Word (.docx, editable) AND PDF, rendered from the SAME report
        model (§3.1) so they cannot drift. (No server-side DOCX→PDF — no LibreOffice.)
  R-D5  CONTRACTOR COPY — the same document for every role, for now.
  R-D6  SIGNATORIES — NONE for now: it is a SYSTEM-GENERATED report. Replace §2's
        section 12 with a closing statement ("System-generated report — CSPC PMU
        Project Monitoring System", generated by <user> on <date/time>, period).
  R-D7  PHOTOS — NOT for now. ⏸ POSSIBLE FEATURE: inspection photos of the period
        (attachments context 'inspection') as a photo-documentation section.
  R-D8  THE PMU'S OWN FORMAT — asked before; the answer was vague ("wala naman" —
        roughly "there isn't one"). ⏸ PARKED AS A POSSIBLE FEATURE: if the PMU or the
        consultant ever supplies a report form, align §2 to it.

===============================================================================
  7. FILES THIS WOULD TOUCH (indicative)
===============================================================================

  NEW   app/Services/Reports/ProjectReportDataService.php
        app/Services/Reports/ScurvePngRenderer.php
        app/Services/Reports/ProjectReportDocxRenderer.php
        tests/Feature/ProjectReportTest.php
  EDIT  bootstrap/app.php (+ 2 blades) — BOM only
        app/Services/ReportExportService.php, app/Http/Controllers/ReportExportController.php
        routes/web.php (3 role groups) · components/header.blade.php (dropdown)
        composer.json / composer.lock (phpoffice/phpword)
        tests/Feature/ReportExportTest.php (test_summary_view_uses_nl2br_for_analysis_text
        pins the raw-marker behaviour — replace it)
  MAYBE resources/views/reports/project-summary.blade.php (R-D4)

===============================================================================
  8. SESSION LOG
===============================================================================

  2026-09-21 — Restudy done (read-only). Traced the export pipeline, the current
    template, all report-relevant tables/services and the document viewer; checked
    PhpWord's requirements against this PHP; measured the BOM in real downloads
    (PDF ×7 and the Sep-17 .xlsx). Plan written. No code changed.
  2026-09-21 — User answered R-D1, R-D2, R-D4..R-D8 (§6); asked for R-D3 to be
    explained. Order: do the BOM fix FIRST; the user will check if it fixes Excel.
  2026-09-21 — P0 BOM FIX ✅ BUILT on phase-38-remove-utf8-bom (from develop f6114a4).
    · Scan of the repo (excluding vendor/node_modules/storage/.git/sampledocs and the
      other tool's .kilo/worktrees copy, left untouched): 5 files started with EF BB BF —
      bootstrap/app.php (THE cause: loaded on every request, so every response began
      with the BOM), resources/views/auth/login.blade.php and
      projects/project_detail/components/modals/issues_modal.blade.php (their own page
      HTML only), public/css/admin/projects/issues-modal.css and
      public/js/admin/projects/issues-modal.js (harmless in browsers). All 5 stripped;
      each diff is line 1 only. .env is clean.
    · Live check: GET http://127.0.0.1:8000/login now starts "<!DOCT" (3c 21 44 …);
      CLI scripts that boot the app no longer print a leading BOM.
    · Guard: tests/Unit/NoUtf8BomTest.php scans app, bootstrap (not cache), config,
      database, routes, lang, resources, public/js, public/css, tests for a leading BOM
      (+ asserts >100 files were scanned, so it cannot pass vacuously). A feature test
      cannot catch this — the BOM is echoed while the framework boots, outside the
      Response object. Mutation: BOM put back on bootstrap/app.php → red, naming it.
    · PHP suite 1281 passed / 0 failed.
    · ⬜ OWED (user): download an Excel export and open it in Excel. If it opens clean,
      the August "corruption" (report_generation_plan.md §6.4) is explained and the
      Excel freeze can be lifted; also re-download the summary PDF (should start %PDF-).

# DOCUMENT PARSER ACCURACY IMPROVEMENT PLAN
# CSPC PMU System — Make Project Creation Automated & S-Curve Import Layout-Agnostic
# Date: September 3, 2026
# Branch: TBD — branch FROM `phase-3-pdf-scurve-import` (latest parser work)
# Status: ⬜ NOT STARTED — plan for potential implementation

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name / Client / Framework
  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).
  Existing AI uses Google Gemini via config/services.php: gemini.key =
  env('AI_API_KEY'), gemini.model = env('AI_MODEL', 'gemini-1.5-flash') —
  see app/Services/AI/AIExplanationService.php:381 callGemini().

Frontend convention (IMPORTANT)
  App UI JS/CSS lives in `public/` linked via asset('...') — NO @vite for new
  features. Feature scripts: public/js/admin/projects/*.js.

Feature area
  Two parsers in the create-project wizard:
  1) Normal PDF document parser — reads contract/supporting PDFs to pre-fill
     Title, Location, Description, Budget, Start/End Dates, Duration, Contractor.
     Files: public/js/admin/projects/document-parser.js (orchestration) +
            public/js/admin/projects/document-parser-core.js (pure)
            public/js/vendor/pdfjs-loader.js (PDF.js CDN loader)
            app.blade.php / stafflayouts / _create_project_modal.blade.php

  2) S-curve import — turns a planned S-curve into baseline months:
     Excel path: app/Services/SCurveImportService.php (PhpSpreadsheet ^4.0)
                + app/Http/Controllers/SCurveImportController.php
                + public/js/admin/projects/scurve-import-core.js /
                  scurve-import-ui.js
     PDF path:   public/js/admin/projects/scurve-pdf-parser-core.js (pure) +
                public/js/admin/projects/scurve-pdf-parser.js (wrapper)
     Both return { months: [{month_index, planned_progress_percent, ...}],
                  meta: {contractAmount, warnings} } — client derives dates.

Related plan
  textfiles/document_parser_and_scurve_import_plan.md (Phases 1-4 implemented)

===============================================================================
  2. STATUS SUMMARY — What Exists Today
===============================================================================

  Phase 1 (normal PDF autofill)            ✅ IMPLEMENTED — works for simple
     text PDFs, but still has wrong parses for varied layouts.

  Phase 2 (Excel S-curve)                  ✅ IMPLEMENTED — accurate for the
     1 confirmed sample (Updated-S-Curve-3-Storey-Bldg.xlsx) but hardcoded
     assumptions (paired columns, header synonyms) break on other contractors.

  Phase 3 (PDF S-curve, client-side pdf.js) ✅ IMPLEMENTED — 2 layouts only:
     (a) monthly columns (Description + Month 1..N) and
     (b) weekly schedule (WEEK 1..12 under month spans).
     Scanned PDFs (18pp Ongoing Project etc.) → fallback "upload xlsx".

  Phase 3 follow-up (Gemini OCR)           🔜 PLANNED, NOT BUILT — this plan
     replaces it with a broader accuracy push.

  In short: all three parsers use the SAME strategy — look for keywords to
  find the table, then use layout (x/y positions or Excel columns) to add it
  up. That strategy is brittle because different contracts use different words
  and different table shapes. The "government S-curve" sample is NOT the only
  template — it is just the only one proven to parse correctly today.

===============================================================================
  3. PROBLEM STATEMENT — Plain Language
===============================================================================

  3.1 What the consultant asked
  -----------------------------
    "How are we doing the parsing?" — answer: keyword labels + layout geometry.

  3.2 Normal PDF issues (supporting docs / contracts)
  ---------------------------------------------------
    - Mostly same layout, but title/budget/date labels vary: "Approved Budget
      for the Contract (ABC)" vs "Contract Amount" vs "Project Cost" vs
      bare "ABC: ₱ 1,234,567". Some values are on the NEXT line under the
      label, not the same line.
    - Multi-column / table PDFs scramble lines if only Y position is used.
    - False positives: an ambient "₱ 12,000" in a page footer was matched as
      the budget.
    - Scanned PDFs = no text at all → needs a photo-to-text step.

  3.3 S-curve issues (the hard one)
  ---------------------------------
    - Different contractors → different S-curve layouts: Month 1..12 in 12
      columns vs months as JAN/FEB vs WEEK 1..20 vs merged header cells vs
      single-column months vs paired columns (value written twice).
    - A3 2-page overflow (page 2 = columns 9-12) needs merging logic.
    - Summary rows ("CUMULATIVE %", "COST") look like activity rows.
    - Excel hardening helps but every new contractor can break it with a
      synonym not in the hardcoded list.
    - Activities extracted but not grouped to correct months: the working
      Excel parser (Updated-S-Curve-3-Storey-Bldg.xlsx) extracts all 59
      activities but the assignment to their monthly buckets appears off
      (monthPesoTotal grouping via SUM(pair) I:J etc. vs Setup modal
      `baseline-schedule-setup-modal.js:150` per-month `activities[]`
      checklist). Cause TBD — could be `SCurveImportService.php:458`
      paired-column inference leaking to wrong spans, or the modal's flat
      `description = names.join(', ')` grouping vs `ProjectActivity` per
      `month_label` linkage. Needs targeted QA (see §8 New Question + §11).

  3.4 Known Issue — Activity Month Grouping (NEW, to verify)  ⚠️ OPEN
  -------------------------------------------------------------
    - Report 2026-09-03: "activities did not able to assign the projects
      properly to their own months" — unsure if parser fault
      (`SCurveImportService.php:510` activitiesByMonth loop / `scurve-
      import-core.js:62` passthrough) or system grouping fault
      (`baseline-schedule-setup-modal.js:1618` display/payload or
      `BaselineScheduleController::storeInitialBaselineSetup:425` DB).
    - Current code on paper correctly loops all months `if(value>0)` per
      month (not `break` after first), verified via explore 2026-09-03.
      Test gap: SCurveImportTest checks `count(activities)=59` not per-month
      distribution, so a swap is not caught.
    - Logged as issue to investigate; flagged for future fix without
      delaying plan commit. Will be verified in Step 0 QA before any code.

  Goal of this plan: make the parser "as accurate as possible so creating a
  new project is automated, manual input is only for correction, and the
  inaccuracy rate keeps getting smaller" — while staying on the FREE AI tier.

===============================================================================
  4. CHOSEN DIRECTION — Hybrid Pipeline, Data-Minimized, Free-Tier Friendly
===============================================================================

  Principle: Keep the fast, free, offline parser as Tier 1. Only call the
  AI when Tier 1 is unsure or fails. Send the smallest possible piece of
  the file to the AI.

  Tier 1 — Local, free, instant (always runs)
    Normal PDF: pdf.js → groupItemsIntoLines → findLabeledValue (expanded
                synonym dict + fuzzy label match) → parseProjectInfo
    Excel     : PhpSpreadsheet (keep — no replacement needed)
    PDF S-curve: pdf.js with x/y → parseSCurvePdf / parseWeeklySchedulePdf
    If confidence is high → show preview modal directly (no AI cost).

  Tier 2 — AI fallback, minimized data (only if Tier 1 confidence low)
    Trigger: countFilled < 3 fields for normal PDFs, or S-curve returns null
             or < 2 months, or warnings[] non-empty, or user clicks "Try AI".
    Action: server-side Gemini 2.0 Flash reads ONLY the relevant pages
            (not the whole 50-page PDF) rendered to images or a 2-page PDF
            slice, via inline_data (base64 + mimeType). Prompt asks for
            strict JSON, server validates & clamps it to the same
            {months, meta} shape.
    Why Gemini: you ALREADY call Gemini server-side (AIExplanationService,
                AIRecommendationService) so no new provider key is needed,
                and it understands any table layout without hardcoding synonyms.

  Tier 3 — Offline fallback (if client rejects cloud at deployment)
    Optional: poppler pdftotext -layout -bbox via spatie/pdf-to-text so
    layout text is preserved without AI. Less accurate than Gemini but
    better than smalot/pdfparser which strips all coordinates.

  Data minimization to stay on free tier:
    - Never send the whole contract. For S-curve, extract only pages that
      contain "S-CURVE" / "SCHEDULE" / "Month 1" tokens (1-3 pages).
      Render those pages to 150 DPI PNGs (small) client-side, upload as
      images — cheaper than a 5 MB PDF.
    - Cache: if file hash seen before, reuse last result.
    - Rate-limit: one AI call per file, not per page.

  Transparency note: CSPC government projects ARE meant to be transparent
  (public bidding). Current AI sends only numbers (title, progress, R²) not
  person data or full documents. Sending a 2-page S-curve slice is consistent
  with that — still public schedule data. If a document contains a private ID
  we would strip it before sending.

===============================================================================
  5. LIBRARY / IMPORT RECOMMENDATIONS
===============================================================================

  5.1 Keep (no new dep)
  -----------------------
    phpoffice/phpspreadsheet ^4.0  — Excel gold standard. No replacement.
    pdfjs-dist@4.4.168 (via public/js/vendor/pdfjs-loader.js) — only JS
      lib that gives x/y coordinates. Keep CDN + add local fallback copy
      in public/js/vendor/ for offline dev.

  5.2 Add for Tier 2 (recommended, small)
  ---------------------------------------
    google/apiclient is ALREADY in composer.json — reuse it. No new
    composer package strictly needed; the Gemini call is a plain HTTP POST
    via Illuminate\Support\Facades\Http (same as AIExplanationService:393).
    If you prefer a typed SDK: `composer require google/cloud-ai-platform`
    is overkill — plain Http is lighter and matches existing code.

    Optional helper for prompt validation:
      No new library — use json_decode + manual clamp (same as
      SCurveImportService monotonic clamp). Keeps dependencies lean.

  5.3 Add only if you need offline (Tier 3, requires sysadmin)
  -------------------------------------------------------------
    spatie/pdf-to-text  `composer require spatie/pdf-to-text`
      + poppler-utils binary (choco install poppler / apt install poppler-utils)
      Wraps `pdftotext -layout -bbox`. Gives layout-preserved text with
      x/y when AI is disabled. Rejected in earlier plan because Windows had
      no binary — document it in README if you choose this path.

    thiagoalessio/tesseract_ocr + intervention/image
      Only if you want better scanned-PDF preprocessing than the current
      tesseract.js CDN. Needs `tesseract-ocr` binary on server. Heavier,
      not needed when Gemini vision is available.

  5.4 Do NOT add
  --------------
    smalot/pdfparser — strips bbox, not table-aware. Will make S-curve worse.
    maatwebsite/excel — just a wrapper over PhpSpreadsheet, no benefit.
    AWS Textract / Azure Document Intelligence — excellent table bbox but
      paid, heavy SDK, requires AWS/Azure account. Gemini free tier covers
      the same need with your existing key.

  Summary: for a Laravel project the MOST ACCURATE free-tier setup is
    PhpSpreadsheet + pdf.js (local) → Gemini 2.0 Flash vision on a 2-page
    slice (cloud fallback). That beats any pure-PHP parser for varied layouts.

  5.5 Future Python / alternative stack (FLAG FOR FUTURE — no new dep in v1)
  ------------------------------------------------------------------------
    User asked if Python code is better. Tested alternative: Python
    `pdfplumber` (wraps pdfminer.six) is the strongest non-AI table parser
    for PDFs like sampledocs/CONSCHEDSAVINGS.pdf. It keeps x/y like pdf.js
    via `page.extract_tables()` / `page.extract_text(x_tolerance=3)`, so it
    can apply the same 3 rules Gemini described without AI:

      Bottom-up: only consider rows with y < activities bottom
      Monotonic: cumulative %-row strictly increasing → 100%
      Additive Check: Wₙ (weekly %) sum == Cₙ (cumulative %)  Cₙ = Cₙ₋₁ + Wₙ

    The naive regex snippet Gemini gave
      `pct_pattern = re.compile(r'(\d{1,3}\.\d{2})\s*%?')` + monotonic run
      ending at first 100.0
    fails on this file because it loses x-position (cannot map WEEK 4 Dec 31
    26.86% vs WEEK 3 18.24% without month-span x-bins) and it merges the
    2-page overflow into one run. The accurate Python would need the same
    span logic as scurve-pdf-parser-core.js:411 parseWeeklySchedulePdf
    (monthSpan x-30 tolerance + nearestPct:395) plus table extraction.

    Other Python options evaluated:
      - Camelot (lattice mode, ghostscript, excellent for bordered tables but
        heavy, needs Java/Ghostscript) — overkill for campus VPS.
      - Tabula-py (Java, lattice) — similar heavy dep.
      - PyMuPDF (fitz) — fastest x/y + rectangle drawing, lighter than Camelot,
        good alternative to pdfplumber if Python is added.
      - PyPDF2/pypdf — text only, no bbox → same failure as smalot/pdfparser.

    Architecture if Python is ever added (OPT-IN, Tier 3):
      Keep SCurveImportService.php for Excel. Add
      python/extract_scurve.py  (requirements.txt: pdfplumber==0.11 or PyMuPDF)
      called from Laravel via Symfony\Component\Process or queued Job:
        Process::run(['python', base_path('python/extract_scurve.py'), $pdfPath])
      → returns same {months,meta} JSON for SCurveImportController.
      Trigger only when client-side pdf.js returns null AND scanned PDF
      (same Opt-in gate as Step 4). Deployment constraint: shared hosting
      usually blocks Python/Java; VPS/Docker OK.

    Decision: Do NOT add Python in v1. The 3-rule improvement (Additive Check
    + Bottom-up) can be done in 10 lines inside scurve-pdf-parser-core.js:379
    findCumulativeRow() without a new runtime — already queued in Step 2b.
    Flag pdfplumber / PyMuPDF as Future Tier 3 if free-tier Gemini + JS
    heuristics still miss a 3rd contractor template.

===============================================================================
  6. IMPLEMENTATION STEPS
===============================================================================

  STEP 0 — Branch & baseline tests                          ⬜ NOT STARTED
    - Branch FROM phase-3-pdf-scurve-import: 
        phase-5-parser-accuracy  (Rule 12: phase-<n>-<short-desc>)
    - Record baseline accuracy: run document-parser-core.test.mjs (19 tests)
      + scurve-pdf-parser.test.mjs (11) + SCurveImportTest (12) on real
      sampledocs/ files. Log current pass/wrong-parse cases.

  STEP 1 — Expand normal PDF keyword robustness (Tier 1)    ⬜ NOT STARTED
    File: public/js/admin/projects/document-parser-core.js

    1a. Label dictionaries (what the parser looks for BEFORE the colon)
        Current labels are in findLabeledValue patterns — expand them:
        - TITLE: keep project title|name of project|project name|title of project
          (no change — title value keywords handle the rest).
        - LOCATION: add Project Site|Site Address|Barangay|Brgy. to
          existing location|project site|site address|project location|barangay|address.
        - BUDGET: expand to cover ABC as a standalone label:
            /(?:approved\s*budget\s*for\s*the\s*contract(?:\s*\(abc\))?|abc|contract\s*amount|approved\s*budget|project\s*cost|total\s*cost|budget|contract\s*price)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i
          Before split: extractBudget writes to approved_budget. After Step 1b
          it must distinguish ABC vs Contract Price (two fields). Add new
          extractABC() vs extractBudget() or return both {budget, abc}.
        - BUDGET SOURCE (NEW): new extractor extractBudgetSource()
            labels: source of fund|funding source|source of funds|budget source|
                    fund source|funding agency|source of financing
            value normalizer: lenRange(3,100), then table-lookup (see Step 1c).
        - CATEGORY (NEW): new extractor extractCategory()
            labels: category|project category|project type|type of project|
                    nature of project|classification
            value normalizer: lenRange(3,50), then table-lookup (see Step 1c).
        - CONTRACTOR: keep contractor|awarded to|proponent|company name|firm name
          (add winning bidder|contractor name as synonym).
        - DATES: add NTP Date|Notice to Proceed|Commencement to startDate;
          keep endDate labels as-is. Make findLabeledValue try same-line,
          next-line, AND next-two-lines (BOQs put value 2 lines below label).
        - Add fuzzy label match (Levenshtein ≤2) for OCR typos
          ("Aproved Bdget", "Contarct Amount") — optional, low priority.

    1b. Value-side keyword dictionaries (HARDCODED for now, DB-driven later)
        Used ONLY as Tier 2 fallback when NO label matched — never replaces
        label search. If a line contains a value keyword, treat it as a
        low-confidence candidate (60) vs label hit (95). Preview modal still
        requires user Apply.

        Title value keywords (hardcoded list from user — Project title values):
          const TITLE_VALUE_KEYWORDS = [
            'provision of', 'completion and provision of',
            'upgrading/renovation of', 'upgrading', 'renovation',
            'embankment works', 'rehabilitation of', 'refurbishment of',
            'rehabilitation/refurbishment of', 'rehabilitation/rectification of',
            'enhancement of', 'analysis, design, and retrofitting',
            'analysis', 'retrofitting', 'restructuring/rehabilitation',
            'restructuring', 'repair', 'design and build of', 'construction of',
            'completion of'
          ];
          Helper: isTitleValueLine(line) → /\b(provision of|rehabilitation|...)\b/i
          + line length 15-200 + Title Case heuristic. Flag: FLAGGED FOR FUTURE — 
          replace with DB-driven frequency table from projects.title later.

        Contractor value keywords (suffixes inside the name itself):
          const CONTRACTOR_VALUE_KEYWORDS = [
            'engineering enterprises', 'construction and supply',
            'construction & supply', 'construction and development corporation',
            'construction and engineering services', 'construction',
            'builders and construction supplies', 'builders', 'general contractor',
            'trading and construction', 'development corporation'
          ];
          Helper: isContractorValueLine(line) → /\b(construction|engineering|builders|enterprises|corporation|supply)\b/i
          + len 3-150 + contains at least one suffix phrase. Used to score
          an unlabeled line that looks like a company name.

        Budget source value keywords: seeded BudgetSource names
          ['GAA','CHED','DPWH','LGU','GAD','Special Trust Fund',
           'Regular Agency Fund','Foreign Assisted','PPP'] — reuse same
          table-lookup as label path; value keywords are identical to DB names.

        Implementation: new functions isTitleValueCandidate(line),
        isContractorValueCandidate(line) called from parseProjectInfo() AFTER
        all label extractors return null. They return the cleaned line or null.
        Add confidence field so UI can show "guessed from keywords — please verify".

    1c. Table lookup for contractor + budget source + category (Option A — strict)
        Contractor: keep existing tryMatchContractorDropdown() in
          document-parser.js:450 (word-level ≥0.5 match against #contractorSelect
          options). If extracted contractor has no ≥0.5 match, keep the raw text
          in the preview input but add warning indicator:
            showParseIndicator('contractorSelect', false) →
            "Contractor \"XYZ Builders\" not found in database — will be saved as
             text; admin can create the contractor record via Admin → Contractors."
          No auto-create, no new route.

        Budget source: NEW — after extractBudgetSource() returns a raw string
          (e.g., "GAA" or "GAD Trust Fund"), do the same dropdown fuzzy match
          against #projectBudgetSource options (reuse pattern from
          public/js/_create_project_modal.js:956 loadBudgetSources()).
          If no match: preview shows the raw value with warning:
            "Budget source \"GAD Trust Fund\" not in database — select an
             existing source or ask admin to add it via (+) Budget Sources."
          Policy stays admin-only: BudgetSourceController.php:33
          `if role !== admin →403` and @can('create', BudgetSource::class)
          hides the (+) button for staff (web.php:254 staff cannot POST).
          Staff cannot create — they must pick from existing 9 seeded values
          or leave as "Unspecified". Matches user choice Option A.

        Category: same as budget source — match against #projectCategory
          options (4 seeded: New Construction, Renovation, Repair, Maintenance).
          No match → warning + fallback to manual pick.

    - Add confidence scoring: expose { parsed, confidence: 0-100 } where
      confidence = (label hits * 1.0 + value-keyword hits * 0.6) / 8 * 100
      so the UI knows when to offer Tier 2 Gemini.
    - Tests: add fixtures for (i) table-style contract where value is on next
      line, (ii) title matched only via value keywords (no label), (iii)
      budget source standalone "ABC:" label, (iv) contractor suffix match.

  STEP 2 — Excel hardening + Summary-Row Validated read  ⬜ NOT STARTED
    File: app/Services/SCurveImportService.php
    (also mirrors to public/js/admin/projects/scurve-pdf-parser-core.js for PDF)

    2a. Expanded anchor synonyms (rule-based, not positional)
        - isWeightLabel: add "weighted", "allocation", "weightage"
        - isDescriptionLabel: add "Item No.", "Particulars", "Work Items"
        - NEW isCumulativeLabel: /cumulative.*%|planned.*cumulative|%.*accomplished/i
          to find summary rows like "CUMULATIVE % ACCOMPLISHED" or
          "PLANNED CUMULATIVE %" (most contractor S-curves have them per client,
          but CONSCHEDSAVINGS.pdf has NO label — so label is optional,
          pattern %-row fallback handles that file).

    2b. Summary-Row Validated logic (user choice: Validated, not Authoritative)
        Order in buildMonths():
          1) Detect monthSpans via findHeader (already anchor-based).
          2) Try to read summary row: scan rows after header for isCumulativeLabel
             → collect that row's values across monthSpans into summaryPct[month].
             For Excel: read the cell values; for PDF: findCumulativeRow:379
             (strictly increasing %-row max ≥50).
          3) Compute activity-derived pct the old way: monthPesoTotal[month] /
             contractTotal * 100 → activityPct[month] (handles outlier with no
             summary row, e.g., CONSCHEDSAVINGS.pdf weekly layout).
          4) Validated decision per month:
               if summaryPct exists: primary = summaryPct, compare vs activityPct,
                 if |primary - activity| > 1.0 → push warning
                 "Month N summary 6.5% vs activity-derived 6.74% — please verify;
                  using summary value." (sample BUHI: 6.5 vs 6.74 triggers this).
                 emit primary (monotonic clamp, force last 100).
               else: emit activityPct (existing behavior, no regression).
        This keeps BUHI Excel accurate (summary trusted) but gives PMU the
        transparency warning you wanted, and still works for the label-less
        CONSCHEDSAVINGS.pdf (no summary row → activity path).

    2c. Single-column month fallback
        - Some contractors do NOT duplicate the value in two columns — currently
          SUM(pair) would double-count; detect by checking if col+1 is mostly
          empty (>70% null/0) then use MAX not SUM for that month.

    - Surface confidence in meta.warnings: "low confidence: only 2 months found"
      or the Validated mismatch warning above triggers Tier 2 opt-in gate.
    - Tests: add fixtures (i) summary row present + activity mismatch warning,
      (ii) outlier with no summary row (weekly PDF via buildMonthsFromRows),
      (iii) single-column layout.

  STEP 3 — Tier 2 Gemini slice service (server)             ⬜ NOT STARTED
    New file: app/Services/AI/GeminiDocumentParserService.php
      - method parseProjectSlice(array $pageImagesBase64): ?array
        Prompt: "Extract JSON {title, location, description, budget,
        startDate, endDate, duration, contractor}. Dates to YYYY-MM-DD.
        Return ONLY JSON. If not found use null." Validate with
        parseToInputDate / asMoney helpers ported to PHP.
      - method parseSCurveSlice(array $pageImagesBase64): ?array
        Prompt: "Extract S-curve months as JSON {months:[{month_index,
        planned_progress_percent}], contractAmount}. Percent cumulative
        0-100, monotonic, last month 100. Return ONLY JSON."
        Validate: monotonic clamp, cap 100, force last 100 (same as
        buildMonthsFromRows), contractAmount numeric check.
      - Both use config('services.gemini.key') + Http::timeout(30)
        same pattern as AIExplanationService.php:381 callGemini().

    New file: app/Services/AI/GeminiSCurveOcrService.php (optional split)
      or keep both methods in one service — prefer one service to avoid
      coupling (Rule 6: single responsibility per service — so 2 services
      is cleaner).
      For S-curve, instruction must include Validated handling: prompt asks
      for summary row if present, else activity-derived; server then does
      the same Validated clamp/warning as Step 2b before returning.

    New controller methods: SCurveImportController::parseWithGemini()
      + DocumentParserController::parseWithGemini()  (or extend existing
      parseExcel) — accept `file` or `document_id`, extract slice pages,
      call service, return {success, months/meta} or {parsed}.

    Routes: POST /{role}/s-curve/parse-gemini and
            POST /{role}/document/parse-gemini in routes/web.php
            (all 3 role groups, same as existing s-curve routes).
      NOTE Q2 = Opt-in: no auto-popup route change — the Gemini endpoint is
      only called when user clicks "Try AI assist" or "Show advanced mapping"
      after a low-confidence / Table-miss banner.

    Data minimization: new helper app/Services/PdfSliceService.php
      - Given PDF path, use Smalot? No — use Imagick/Poppler to render
        only pages containing S-CURVE keywords, or do it client-side:
        client renders 2 pages to canvas via pdf.js → canvas.toDataURL →
        uploads base64 images (smaller than PDF). Prefer client-side slice
        to avoid poppler binary.

  STEP 4 — Client-side confidence gate & slice upload (Opt-in) ⬜ NOT STARTED
    Files: public/js/admin/projects/document-parser.js
           public/js/admin/projects/scurve-import-ui.js
           resources/views/admin/_create_project_modal.blade.php
           resources/views/admin/_manage_budget_sources_modal.blade.php (NO CHANGE)
    - After Tier 1 parse, compute confidence:
        normal: confidence = (label hits + 0.6*valueKeyword hits)/8, plus
                table-match bonus (+10 if contractor/budget source found in DB)
        s-curve: confidence = months.length >=3 && warnings.length==0
                 && no Validated mismatch warning ? high : low
    - If low OR if any Validated mismatch warning (Step 2b) OR any Table-lookup
      miss (contractor/budget source/category warning from Step 1c): show
      an OPT-IN amber banner (not auto-popup, per Q2) under the preview
      button with a discreet link:
        "Parsing looks uncertain — [Try AI assist (free)?]  ·  [Show advanced mapping]"
      The first link calls the new Gemini endpoint with the pre-rendered
      slice images (or the original File if images not ready); the second
      opens the Guided Column Matcher (see §11) — both require explicit
      user click, they do NOT block Next. This satisfies Opt-in: wizard
      stays quiet unless user asks for help.
      For Table-miss, also show the Option A warning inline next to the
      field (see Step 1c) — staff sees:
        Budget source "GAD Trust Fund" not in database → please select from
        existing 9 sources or ask admin to add via (+) (admin only).
        Contractor "XYZ Builders" not found → will be kept as text.
      No new add-modal for staff — admin-only create is preserved
      (BudgetSourceController.php:33 + Policy create:admin only).
    - On Gemini success: merge AI result into preview modal (user still reviews
      before Apply — never auto-apply without confirmation). If Gemini also
      returns an unknown budget source, same Option A warning applies (do not
      auto-create).
    - Reuse existing preview modal pattern (editable inputs, Apply reads
      corrected values). Add a dedicated budget-source/contractor warning
      area below the table-matched indicator. Add a small Validated note
      "Using summary row value (activity check: 6.74% — mismatch flagged)"
      when Step 2b triggers.

  STEP 5 — Polish & tests                                   ⬜ NOT STARTED
    - tests/js/document-parser-core.test.mjs: add low-confidence fixtures.
    - tests/Feature/SCurveImportTest.php: keep, add Gemini service mocked
      test (Http::fake).
    - tests/js/scurve-pdf-parser.test.mjs: no change.
    - Manual QA: run against all sampledocs/ (Completed / Ongoing / BUHI)
      log accuracy % before/after.
    - Cache-bust: filemtime ?v= on new scripts (same as Phase 1 fix).

  STEP 6 — Logs & review                                    ⬜ NOT STARTED
    - Update this plan + textfiles/bugs_and_behavior_adjustments.md
      (Rule 11) with session log: date, what changed, user testing result,
      test counts (composer test 725+ / npm run test:js 39+).
    - Do NOT merge to main without explicit confirmation (Rule 12).

===============================================================================
  7. SESSION LOGS
===============================================================================

  2026-09-03 — Plan written (no code changed).
    - Requested by user to write an implementation plan (not for consultant
      review, for internal dev). Mode switched plan→build to allow writing.
    - Source: re-read document-parser-core.js 366 lines, document-parser.js
      549 lines, scurve-pdf-parser-core.js 537 lines, SCurveImportService.php
      637 lines, and AIExplanationService.php 441 lines to confirm current
      keyword vs layout behavior.
    - Decided on hybrid Tier1 (local) + Tier2 (Gemini slice, free, minimized)
      direction per user constraints: must stay on free AI tier, data
      minimization, gov transparency precedent OK.

  2026-09-03 — Plan discussion update (no code changed).
    - User listed project-list title keywords (Provision of, Rehabilitation…,
      Design and Build of, etc.) and contractor suffixes (engineering
      enterprises, construction and supply, etc.). Asked if value-side
      keywords can be added and if contractor/budget_source table lookup +
      add-modal is possible with admin-only budget source.
    - Clarified: current parser is label-anchored (Contractor: → value);
      value keywords not used. User confirmed wants hardcoded value lists
      for now (DB-driven later) and Option A (strict admin-only, no staff
      create — just warning + manual fallback).
    - Updated Step 1 (1a label dict + 1b hardcoded TITLE_VALUE_KEYWORDS/
      CONTRACTOR_VALUE_KEYWORDS + 1c Option A table lookup) and Step 4
      (confidence gate shows Table-miss warning, preserves admin-only
      Policy). Mode is now build — ready to implement but user said "no
      implementation yet".

  2026-09-03 — Plan update for Validated + Opt-in (no code changed).
    - User: "To handle arbitrary layouts ... rule-based anchor heuristic"
      suggestion. User liked Summary Row Targeting but noted outlier
      sampledocs/CONSCHEDSAVINGS.pdf has NO "CUMULATIVE % ACCOMPLISHED"
      label. Confirmed: that PDF is weekly layout — findCumulativeRow:379
      finds the increasing %-row by pattern, not label, so Validated logic
      must handle both labeled and label-less files.
    - User decided Q1 = Validated (trust summary row but warn if activity
      sum differs >1%) and Q2 = Opt-in (column matcher hidden behind link,
      not auto-popup).
    - Updated Step 2 (2a synonyms + 2b Validated primary+warning branch +
      2c single-col fallback) and Step 4 (Opt-in banner with two links,
      not auto-popup) plus §11 flagged entry for guided matcher. Plan stays
      implementation-ready, no code touched.

  2026-09-03 — Activity grouping issue noted (no code changed).
    - User: working Excel parser extracts all 59 activities but "did not able
      to assign the projects properly to their own months" — unsure if parser
      or system grouping fault.
    - Explore 2026-09-03 verified SCurveImportService.php:458 paired SUM + 510
      loop adds to each month with value>0 (no break) and core passthrough is
      1:1, but tests lack per-month distribution asserts (test gap).
    - Logged as §3.4 Known Issue + §8 open question + §11 flagged item.
      Flagged for Step 2d / Step 5 verification before implementation. No code
      touched per user request "for now please note as issue".

===============================================================================
  8. OPEN QUESTIONS / STILL TO RESOLVE
===============================================================================

  - Should the image slice be rendered client-side (canvas.toDataURL, no
    server binary) or server-side (poppler/Imagick)? Recommendation: client-
    side for zero ops cost — decide in Step 3 kickoff.
  - Free quota: gemini-1.5-flash free is ~15 RPM; if PMU batch-creates
    10 projects at once, is throttling needed? Likely not for campus scale.
  - Should we keep tesseract.js as a third fallback or remove it to
    simplify? Recommendation: keep but hide behind Gemini — only run
    tesseract if Gemini key missing.
  - NEW 2026-09-03: Activity month grouping — is the bug in
    SCurveImportService.php:458 SUM(pair) vs single-column file, or in
    baseline-schedule-setup-modal.js per-month activities[] vs joined
    description? Need a minimal Excel fixture: activity A with pesos
    only in Month 1 (I+J), activity B only in Month 2 (K+L) and check
    that payload months[0].activities = [A], months[1].activities = [B]
    not [A,B] lumped. Also verify J→K gap !=2 edge.

===============================================================================
  9. DECISION LOG
===============================================================================

  Decision 1 — Keep PhpSpreadsheet, do not add new Excel library
    Context: User asked best imports for Laravel accuracy.
    Options: maatwebsite/excel, box/spout, PhpSpreadsheet.
    Decision: Keep PhpSpreadsheet ^4.0.
    Rationale: Already the best; Excel issue is not the library but the
      synonym/header variance which is fixed with small code hardening.
    Consequences: No migration, no new dep, tests stay green.

  Decision 2 — Gemini as fallback, not primary, and slice-only
    Context: Need accuracy for varied S-curve layouts without paid tier.
    Options: (a) pure regex/layout, (b) full-PDF Gemini, (c) slice Gemini.
    Decision: (c) Tier 1 local always, Tier 2 Gemini on 1-3 page slice only.
    Rationale: Minimizes cost/data sent, stays on free tier, respects
      government transparency + data minimization principle the user asked.
    Consequences: One extra endpoint + JS confidence gate; no whole-doc send.

  Decision 3 — Do not use smalot/pdfparser for S-curve
    Context: Considered server-side PDF text libs.
    Decision: Reject for S-curve (use pdf.js x/y or Gemini).
    Rationale: smalot strips bbox, so layout inference impossible — plan
      already noted this 2026-08-19.
    Consequences: No new PHP PDF dep for Tier 1.

  Decision 4 — Client-side page slicing over server poppler
    Context: How to get the 2 relevant pages to the AI?
    Options: server poppler binary vs client canvas.
    Decision: Prefer client canvas-to-image (no server binary install).
    Rationale: Works on current Windows dev stack, zero ops, smaller
      payload than whole PDF.
    Consequences: Slightly more JS, but reuses existing pdf.js render path.

  Decision 5 — Hardcoded value-side keyword lists (not DB-driven yet)
    Context: User asked if title/contractor value phrases can be matched
      when no label exists, and wants DB-driven later.
    Options: (a) hardcoded arrays now, (b) DB-driven from projects/contractors
      frequency tables.
    Decision: (a) Hardcoded — TITLE_VALUE_KEYWORDS (15 phrases from user)
      and CONTRACTOR_VALUE_KEYWORDS (6 suffixes from user) in
      document-parser-core.js as secondary fallback (confidence 60 vs label 95).
    Rationale: Fast to implement, deterministic for current sample set;
      DB-driven needs a migration + learning threshold not justified yet.
    Consequences: Step 1b adds two const arrays + helpers
      isTitleValueCandidate / isContractorValueCandidate; flagged for future
      DB-driven replacement in §11.

  Decision 7 — Summary-Row Validated (not Authoritative)
    Context: Suggestion Summary Row Targeting — read CUMULATIVE % row
      directly vs activity sum. Outlier sampledocs/CONSCHEDSAVINGS.pdf has
      NO such label (weekly layout, %-row found by pattern findCumulativeRow:379).
    Options: (A) Authoritative — trust summary, ignore activity; (B) Validated
      — trust summary but warn if |summary - activity| >1%.
    Decision: (B) Validated — Step 2b primary = summaryPct, warning if
      |6.5% - 6.74%| >1% (BUHI case); else activityPct when no summary row.
    Rationale: Matches user choice 2026-09-03 leaning to validation; gives PMU
      transparency (CSPC audit needs to see mismatch) without ignoring contractor
      written summary.
    Consequences: buildMonths() adds isCumulativeLabel + dual-path logic +
      meta.warnings entry; scurve-pdf-parser-core.js adds same for PDF.

  Decision 8 — Opt-in Column Matcher (not Auto-popup)
    Context: Guided Pre-Import Wizard — Which column is Description? [dropdown]
      per contractor template.
    Options: (A) Opt-in link "Show advanced mapping", (B) Auto-popup on failure.
    Decision: (A) Opt-in — Step 4 amber banner shows two links
      [Try AI assist] · [Show advanced mapping] only after low confidence /
      Validated mismatch / Table-miss; never blocks Next.
    Rationale: Matches user choice 2026-09-03; less friction for 80% of files
      that already parse; advanced mapping still available when needed.
    Consequences: No parser_templates migration in v1; flagged for future
      per-contractor column_map JSON + _template_mapper_modal.blade.php.

  Decision 6 — Option A for contractor/budget_source Table-miss (admin-only)
    Context: BudgetSourceController.php:33 and Policy create:admin only —
      staff cannot POST budget sources; contractor similarly admin-managed.
      Missing value should prompt an add-modal?
    Options: (A) strict — warning + manual fallback, (B) staff proposes
      pending row is_active=false + admin approval.
    Decision: (A) Strict — show amber warning next to field:
      "Budget source \"X\" not in database — select existing or ask admin
       via (+) Budget Sources (admin only)". Contractor: keep raw text +
      indicator. No new route, no permission change.
    Rationale: Matches user choice 2026-09-03; preserves government audit
      requirement that only admin can create reference tables; minimal
      code and no notification workflow.
    Consequences: Step 1c reuses existing showParseIndicator + dropdown
      fuzzy match; no new BudgetSourcePolicy change; staff stays
      role:staff read-only (web.php:254).

===============================================================================
  10. FILES TO TOUCH (when implementation starts)
===============================================================================

  NEW
    app/Services/AI/GeminiDocumentParserService.php
    app/Services/AI/GeminiSCurveOcrService.php  (or single combined service)
    app/Services/PdfSliceService.php  (if server-side slice)
    tests/Feature/GeminiParserTest.php (mocked Http::fake)

  MODIFY
    public/js/admin/projects/document-parser-core.js  (synonyms, confidence)
    public/js/admin/projects/document-parser.js       (confidence gate, AI button)
    public/js/admin/projects/scurve-import-ui.js      (confidence gate, slice upload)
    app/Services/SCurveImportService.php              (synonyms, single-col fallback)
    app/Http/Controllers/SCurveImportController.php   (Gemini endpoint)
    routes/web.php                                     (2 new POST routes x3 roles)
    resources/views/admin/_create_project_modal.blade.php (AI assist button)
    resources/views/projects/project_detail/components/... (baseline button note)

  NO CHANGE (keep)
    public/js/admin/projects/scurve-pdf-parser-core.js (already handles 2 layouts;
      extend only if a new layout appears after QA)
    composer.json  (no new PHP dep required for Gemini Http path)
    package.json   (no new npm dep)

===============================================================================
  11. FLAGGED FOR FUTURE
===============================================================================

  - Activity month grouping investigation (from §3.4): verify
    SCurveImportService.php:510 activitiesByMonth leak, single-column
    vs paired inference, and baseline-schedule-setup-modal.js grouping;
    add per-month tests (see §8). Flagged for immediate Step 2d if confirmed
    before implementation — otherwise carry into Step 5 polish.

  - If a new S-curve layout appears (e.g., quarterly columns Q1..Q4), add a
    third layout path in scurve-pdf-parser-core.js following the weekly-
    schedule pattern (detect Q row, map to months). For Opt-in matcher,
    add a new table parser_templates (id, contractor_id FK nullable,
    source_type enum excel/pdf, column_map JSON, created_by) + UI
    resources/views/admin/_template_mapper_modal.blade.php that reuses
    findHeader's anchor scan to propose mappings and saves per-contractor.

  - Summary-Row Validated tuning: threshold currently |summary - activity| >1%
    → warning; after QA on BUHI vs others, tighten to 0.5% or loosen to 2%
    based on false-positive rate.

  - If free Gemini quota becomes a concern, add server-side response caching
    keyed by file SHA256 (store in cache for 30 days).
  - Replace hardcoded TITLE/CONTRACTOR value keyword arrays with
    DB-driven dictionaries after enough projects exist: query
    `SELECT title FROM projects` and `SELECT company_name FROM contractors`,
    extract top word bigrams, store in a new `parser_keywords` table +
    admin CRUD modal (same pattern as categories). Threshold: ≥3 occurrences.

  - If Option A proves too friction-heavy for PMU staff, revisit Option B:
    `budget_sources.status = pending` + admin notification + approve/reject.
  - If client later requires fully offline (no cloud), revisit
    spatie/pdf-to-text + poppler and improve manual layout heuristics.
  - Guided Column Matcher trigger: currently Opt-in link only after low
    confidence; if users miss it, consider auto-popup after 2 consecutive
    low-confidence uploads from same contractor (revisit Q2).
  - Python pdfplumber / PyMuPDF micro-service flagged in §5.5: add
    python/extract_scurve.py + Symfony Process only if JS Additive Check
    (Step 2b) + Gemini slice still miss a layout; requires VPS (shared
    hosting blocks Python). Keep Excel on PhpSpreadsheet, PDF table logic
    mirrored in JS first.

===============================================================================
  12. CONVERSATION CONTINUATION GUIDE
===============================================================================

  For the next session:
    1. Assume Phases 1-4 are stable on phase-3-pdf-scurve-import (not yet
       merged to main per Rule 12).
    2. Start from STEP 0 above — create branch phase-5-parser-accuracy FROM
       phase-3-pdf-scurve-import, not from main.
    3. Before coding Step 3, confirm with user: client-side vs server-side
       slice (Decision 4) and whether to keep tesseract.js.
    4. Run `npm run test:js` and `composer test` after each step (Rule 8).

===============================================================================
  END OF PLAN
===============================================================================

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
    - Re-confirmation 2026-09-04: user double-checked BUHI Excel and
      confirmed grouping is actually accurate ("properly list the activities
      on those months") — the earlier report was a misunderstanding of the
      24 fortnight buckets `I:AF` (15,15,5 days) → 12 months `I:J` etc.
      Keep flagged for Step 2d per-month fixture (see §8) but de-prioritized.
    - Logged as issue to investigate; flagged for future fix without
      delaying plan commit. Will be verified in Step 0 QA before any code.

  3.5 Known Issue — Scanned S-Curve PDF with ## / Weighted %  ⚠️ OPEN
  ---------------------------------------------------------------------
    - New sample `sampledocs/scurvepdfsample2.pdf` (389KB, 04/09/2026,
      1-page Letter 612×792, Skia/PDF m154, `paintImageXObject:1`)
      is a physical-copy photo scan: `pdf.js getTextContent()` Page1: 0 items
      vs control `CONSCHEDSAVINGS.pdf` 2045+915 items — pure image, no
      selectable text layer (Google Docs export of a scanned JPG).
    - Two variants flagged: CONSCHEDSAVINGS `##` is Excel column-width overflow
      (cell value exists but printed as `###`) — current
      `scurve-pdf-parser-core.js:214` isNumericToken `/^\d+(\.\d+)?$/` rejects
      `##` so hasMonthValue stays false and activity is never assigned.
      Physical copy `scurvepdfsample2.pdf` `weighted %` (e.g., `1.20%` per
      month column) is intentional activity weight, not peso — also rejected
      by isNumericToken (needs `%` strip + parseNumeric).
    - Current parsers (5.1-5.5) never see these tokens: monthly path
      detectHeaderColumns:129 header=null, weekly path 411 weekRow=null →
      parseSCurvePdf:489 returns null → UI fallback `upload .xlsx`.
    - Will be handled by Tier 2 OCR (eng+osd) → hOCR x/y + presence flags
      (see §4 Tier 2, §6 Step 3). Keep Excel import as preferred path
      (recommended: require original .xlsx from contractor).

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
                synonym dict + fuzzy label match + value-side keywords) →
                parseProjectInfo + table lookup (Option A)
    Excel     : PhpSpreadsheet (keep — no replacement needed)
    PDF S-curve: pdf.js with x/y → parseSCurvePdf / parseWeeklySchedulePdf
                (Summary-Row Validated: primary summary, compare activity sum)
    If confidence is high → show preview modal directly (no OCR/AI cost).

  Tier 2 — Local OCR, auto (image scans, no cloud)
    Trigger: pdf.js items <10 or header==null (e.g., scurvepdfsample2.pdf
              1-page Letter 389KB Skia/PDF image-only, 0 items) → auto-run.
    Action: reuse existing tesseract.js loader (document-parser.js:142
             _tesseractPromise cached) — render page to canvas@2x via pdf.js
             getPage().render() then Tesseract.recognize(canvas, 'eng+osd',
             { tessjs_create_hocr: '1' }) to get TSV bbox x/y. Convert hOCR
             to synthetic pdf.js items {str, transform[4]=x, transform[5]=y,
             width, hasEOL} and re-feed to scurve-pdf-parser-core.js:76
             groupItemsIntoLines / 379 findCumulativeRow. Handles
             CONSCHEDSAVINGS ## overflow (## → presence flag) and
             physical-copy weighted % (e.g., "1.20%") without cloud.
    Why OCR Tier 2, not AI: user choice 2026-09-04 — OCR first, AI very last;
             keeps PMU on free tier, no doc leaves server, works offline after
             first 30MB WASM cache.

  Tier 3 — AI fallback (very last, minimized data, only if Tier 2 low)
    Trigger: OCR countFilled <3 / months <2 / Validated warnings still low,
              or user clicks "[Try AI assist]" (Opt-in).
    Action: server-side Gemini 2.0 Flash reads ONLY the relevant 1-3 pages
            as 150 DPI PNG slice (client canvas.toDataURL base64, inline_data).
            Prompt asks for strict JSON, server validates & clamps to same
            {months, meta} shape + Validated check (summary vs activity >1%).
    Why Gemini Tier 3: you ALREADY call Gemini server-side
             (AIExplanationService, AIRecommendationService) — no new key,
             understands any table. Used only when local OCR is unsure.

  Tier 4 — Offline poppler fallback (if client rejects cloud at deployment)
    Optional: poppler pdftotext -layout -bbox via spatie/pdf-to-text — less
             accurate than OCR but better than smalot/pdfparser.

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

  5.2 Add for Tier 2 — Local OCR auto (eng+osd) + Tier 3 AI (very last)
  -------------------------------------------------------------------------
    Tier 2 OCR: tesseract.js is ALREADY in system for supporting docs
    (public/js/admin/projects/document-parser.js:142 loadTesseract, 164
    ocrPage, OCR_PAGE_LIMIT 10). Current S-curve PDF has none. For
    scurvepdfsample2.pdf (0 items) reuse the same CDN loader with
    language `eng+osd` (orientation/script detection handles phone-tilted
    physical photos) — user choice 2026-09-04. No new composer dep; the
    30MB WASM is cached after first load (~2s on slow campus Wi-Fi per
    user approval, auto-OCR not Opt-in per user).

    Tier 3 AI: google/apiclient is ALREADY in composer.json — reuse it.
    Gemini call is a plain HTTP POST via Illuminate\Support\Facades\Http
    (same as AIExplanationService:393). If you prefer a typed SDK:
    `composer require google/cloud-ai-platform` is overkill — plain Http
    is lighter and matches existing code. Demoted to very last per user
    `OCR first, AI very last`.

    Optional helper for prompt validation:
      No new library — use json_decode + manual clamp (same as
      SCurveImportService monotonic clamp). Keeps dependencies lean.

  5.3 Add only if you need offline poppler (Tier 4, requires sysadmin)
  ------------------------------------------------------------------------
    spatie/pdf-to-text  `composer require spatie/pdf-to-text`
      + poppler-utils binary (choco install poppler / apt install poppler-utils)
      Wraps `pdftotext -layout -bbox`. Gives layout-preserved text with
      x/y when OCR/AI are disabled. Rejected in earlier plan because
      Windows had no binary — document it in README if you choose this path.

    thiagoalessio/tesseract_ocr + intervention/image
      Only if you want server-side OCR instead of browser tesseract.js.
      Needs `tesseract-ocr` binary on server. Heavier — browser OCR
      (Tier 2) already covers scurvepdfsample2.pdf 1-page scan, so not
      needed when Tier 2 is auto.

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

  STEP 3 — Tier 2 OCR auto (eng+osd) + Tier 3 Gemini very last ⬜ NOT STARTED

  STEP 3a — OCR auto for scanned S-curve PDF (new Tier 2, user choice 2026-09-04)
    Files: public/js/admin/projects/scurve-pdf-parser.js
           public/js/admin/projects/document-parser.js (share loader)
           public/js/vendor/tesseract-loader.js (NEW, optional extract)
    Trigger: auto (not Opt-in) — if pdf.js items <10 (scurvepdfsample2.pdf
             0 items, CONSCHEDSAVINGS 2045 items skip) or header==null.
    Flow:
      - Reuse _tesseractPromise cached loader from document-parser.js:142
        (CDN jsDelivr Tesseract@5). Extend to load with language 'eng+osd'
        (orientation/script detection handles phone-tilted physical photos
        per user approval). WASM ~30MB cached after first load, ~2s on
        slow campus Wi-Fi (user approved auto, not Opt-in).
      - For each textless page (cap 3 pages for S-curve, not 10 like
        supporting docs): page.getViewport 2x → render to canvas →
        Tesseract.recognize(canvas, 'eng+osd', { tessjs_create_hocr: '1' })
        → parse hOCR TSV bbox → synthetic items
          {str, transform:[4]=x, transform:[5]=y, width, hasEOL}
        → groupItemsIntoLines:76 / findCumulativeRow:379.
      - ## / % handling (§3.5): in buildRows monthVals loop, accept
        token.str /^#+$/ as presence flag (hasMonthValue=true, do not sum)
        and /^\d+(\.\d+)?\s*%$/ as numeric (strip % → parseNumeric) so
        CONSCHEDSAVINGS ## overflow and weighted % physical copy both map
        to activity presence without double-counting.
      - Validation: reuse Validated logic §2b (summary vs activity) +
        monotonic 100% + Additive Check Wₙ + Cₙ₋₁ = Cₙ (flag if >0.05 off
        for OCR 8→B misread).
    No new PHP dep; keeps Excel on PhpSpreadsheet. Falls through to Tier 3
    if OCR still <2 months / low confidence.

  STEP 3b — Gemini slice service (new Tier 3, very last)        ⬜ NOT STARTED
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
      NOTE now Tier 3 very last — only called when OCR still low
      confidence / Table-miss / Validated mismatch, and only on user
      "[Try AI assist]" click (keeps Q2 Opt-in for AI, but OCR is auto).

    Data minimization: new helper app/Services/PdfSliceService.php
      - Given PDF path, use Smalot? No — use Imagick/Poppler to render
        only pages containing S-CURVE keywords, or do it client-side:
        client renders 2 pages to canvas via pdf.js → canvas.toDataURL →
        uploads base64 images (smaller than PDF). Prefer client-side slice
        to avoid poppler binary. For scanned image-only PDFs, the slice
        is the already-rendered OCR canvas.

  Ordering: Tier1 pdf.js → Tier2 OCR auto (eng+osd) → Tier3 AI very last
            (user: OCR first, AI last 2026-09-04). Previous plan had AI Tier2;
            swapped per user request.

  STEP 4 — Client-side confidence gate & slice upload (Opt-in for AI, Auto for OCR) ⬜ NOT STARTED
    Files: public/js/admin/projects/document-parser.js
           public/js/admin/projects/scurve-import-ui.js
           public/js/admin/projects/scurve-pdf-parser.js
           resources/views/admin/_create_project_modal.blade.php
           resources/views/admin/_manage_budget_sources_modal.blade.php (NO CHANGE)
    - OCR is AUTO (Tier 2): after Tier 1 pdf.js if items<10 / header==null
      (scurvepdfsample2.pdf 0 items), scurve-pdf-parser.js auto-renders →
      OCR 'eng+osd' without user click (~2s, user approved). Show
      "Scanning documents… (OCR reading scanned pages)" banner
      document-parser.js:178 style, then re-run scurve-pdf-parser-core.js.
    - AI is OPT-IN (Tier 3, very last): after OCR still low confidence
      (months<2 or Validated mismatch Step2b or Table-miss Step1c),
      show amber banner (not auto-popup, per Q2) under preview button:
        "Parsing looks uncertain — [Try AI assist (free)?]  ·  [Show advanced mapping]"
      First link calls Gemini endpoint with the already-rendered 150 DPI slice
      (canvas.toDataURL); second opens Guided Column Matcher (§11). Neither
      blocks Next. This respects user "OCR first, AI very last".

    - Confidence:
        normal: (label hits + 0.6*valueKeyword hits)/8 + table-match bonus
        s-curve: high iff months>=3 && warnings==0 && no Validated mismatch
                 && no ## presence ambiguity; else low → OCR auto, then AI Opt-in.

    - For Table-miss, also show Option A warning inline next to field
      (see Step1c) — staff sees:
        Budget source "GAD Trust Fund" not in database → please select from
        existing 9 sources or ask admin to add via (+) (admin only).
        Contractor "XYZ Builders" not found → will be kept as text.
      No new add-modal for staff — admin-only create preserved
      (BudgetSourceController.php:33 + Policy create:admin only).
    - On Gemini success: merge AI result into preview modal (user still reviews
      before Apply — never auto-apply). If Gemini also returns unknown budget
      source, same Option A warning applies (do not auto-create).
    - Reuse preview modal pattern (editable inputs, Apply reads corrected
      values). Add dedicated budget-source/contractor + OCR/Validated notes
      area below indicator. Add "Using summary row (activity check 6.74% —
      mismatch flagged)" when Step2b triggers, and "OCR read (tilt-corrected)"
      when eng+osd used.

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

  2026-09-04 — Scanned S-curve sample + OCR first, AI last (no code changed).
    - User clarified BUHI Excel was misunderstanding — grouping is actually
      accurate (I:AF fortnight 15,15,5 → 12 months I:J etc., value>0 per
      month correctly assigns multi-month spanning like Field Office 7469×24).
    - New issue: CONSCHEDSAVINGS.pdf ## (Excel column-width overflow) and
      new sample scurvepdfsample2.pdf (389KB Letter Skia image-only 0 items
      vs control 2045 items) physical weighted % scan — both need presence
      handling. User confirmed physical copy scan will be added to sampledocs.
    - User asked about Python pdfplumber: naive regex snippet fails without
      x-bins (December 18.24% vs 26.86% week-span) — recommended JS 3-rule
      improvement instead. Logged as §5.5 future Python flagged, not v1.
    - User decided: OCR first with eng+osd auto (~2s WASM ok) and AI very last.
      Updated §4 Tiers (1 local, 2 OCR auto eng+osd, 3 AI Opt-in, 4 poppler),
      §5.2 Tier split, §6 Steps 3a (OCR auto) / 3b (AI Tier3) / 4 (Auto vs Opt-in),
      §5.5 Python flagged future, §8 + §9 Decisions 9/10 (OCR + ##/% handling),
      §3.5 new scanned issue. Plan stays implementation-ready, no code touched.

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
  - NEW 2026-09-04: Scanned S-curve scurvepdfsample2.pdf (389KB Letter,
    1-page Skia image-only 0 items) + CONSCHEDSAVINGS ## overflow and
    weighted % physical copy: how to treat ## as presence and 1.20%
    as numeric in OCR? Verify in Step 3a that ## → hasMonthValue=true
    and 1.20% → strip % → parseNumeric, and that Additive Check
    Wₙ + Cₙ₋₁ = Cₙ catches OCR 8→B errors. Also verify eng+osd corrects
    phone-tilted photos (Skia Letter scan) without adding latency
    beyond ~2s auto OCR the user approved.

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

  Decision 9 — OCR Auto eng+osd Tier 2 (not Opt-in), AI Tier 3 Very Last
    Context: New sample scurvepdfsample2.pdf 389KB Letter Skia image-only
      0 items vs CONSCHEDSAVINGS.pdf 2045 items text-based; physical copy is
      phone-tilted scan of weighted % S-curve; supporting-doc parser already
      has tesseract.js Tier 2 (document-parser.js:142 eng) but S-curve has none.
    Options: (A) AI first, (B) OCR first auto eng vs eng+osd vs Opt-in link,
      C) Keep Excel-only message.
    Decision: OCR first auto with eng+osd (user 2026-09-04), AI demoted to Tier 3
      very last. Reuse document-parser.js _tesseractPromise cached loader,
      add language eng+osd for photo tilt, auto after items<10 (not Opt-in),
      30MB WASM ~2s approved. Handles CONSCHEDSAVINGS ## (## → presence)
      and weighted % (1.20% → numeric) without cloud.
    Rationale: User: "OCR first, AI very last"; OCR keeps docs on server
      (government transparency, no doc leaves), covers offline after cache,
      already approved for supporting docs.
    Consequences: Step 3a new (OCR auto), Step 3b becomes Tier 3 (AI Opt-in),
      Step 4 split Auto vs Opt-in; scurve-pdf-parser.js + tesseract-loader.js
      share promise; Step 2b adds ##/% presence handling.

  Decision 10 — Handle ## as presence, % as numeric in OCR/PDF S-curve
    Context: CONSCHEDSAVINGS ## is Excel column-width overflow (###) not data;
      scurvepdfsample2.pdf weighted % is intentional per-month weight.
      isNumericToken /^\\d+(\\.\\d+)?$/ rejects both.
    Options: (A) ignore, (B) flag presence.
    Decision: (B) In scurve-pdf-parser-core.js buildRows: /^#+$/ → hasMonthValue
      true (activity in month, no sum); /^\\d+(\\.\\d+)?\\s*%$/ → strip % →
      parseNumeric (active). For ##, do not sum peso; for %, treat as numeric
      presence (weight).
    Rationale: Restores activity checklist per month for scanned/overflow PDFs
      without changing Validated % math (findCumulativeRow already handles %).
    Consequences: §3.5 + Step 3a handling, test fixtures for ## row + % row.

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
    public/js/vendor/tesseract-loader.js  (optional extract, share promise)
    tests/Feature/GeminiParserTest.php (mocked Http::fake)
    tests/Feature/ScurveOcrTest.php (OCR ##/% presence fixtures)

  MODIFY
    public/js/admin/projects/document-parser-core.js  (synonyms, confidence, ##/%)
    public/js/admin/projects/document-parser.js       (share _tesseractPromise, eng+osd)
    public/js/admin/projects/scurve-pdf-parser.js     (auto OCR Tier 2)
    public/js/admin/projects/scurve-import-ui.js      (Auto vs Opt-in gate)
    public/js/admin/projects/scurve-pdf-parser-core.js (## → presence, % numeric + Additive)
    app/Services/SCurveImportService.php              (synonyms, single-col fallback, Validated)
    app/Http/Controllers/SCurveImportController.php   (Gemini Tier3 endpoint)
    routes/web.php                                     (2 new POST routes x3 roles)
    resources/views/admin/_create_project_modal.blade.php (AI assist button + OCR note)
    resources/views/projects/project_detail/components/... (baseline button note)

  NO CHANGE (keep for v1 except §3.5 OCR handling)
    composer.json  (no new PHP dep for OCR — tesseract.js CDN reused)
    package.json   (no new npm dep — tesseract.js CDN, no install)

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

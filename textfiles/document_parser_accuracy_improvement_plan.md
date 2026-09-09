# DOCUMENT PARSER ACCURACY IMPROVEMENT PLAN
# CSPC PMU System — Make Project Creation Automated & S-Curve Import Layout-Agnostic
# Date: September 4, 2026 — Updated: Normal-doc-first priority per user
# Branch: phase-5-parser-accuracy (FROM phase-3-pdf-scurve-import; docs on phase-5-parser-accuracy-plan)
# Status: ✅ Reader accuracy COMPLETE through P1/P2 (JS 62/62, browser-proven incl. verdict buttons). Learning loop CONTINUES in textfiles/document_parser_learning_loop_plan.md (L1-L3, NOT STARTED). S-curve Steps 2-4 still deferred. Branch: phase-5-parser-accuracy.

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

  3.2 Normal PDF issues (supporting docs / contracts) — NOW PRIORITY FIRST
  ---------------------------------------------------------------------------
    Priority: per user 2026-09-04, fix normal contract accuracy BEFORE S-curve.
    Evidence: scanned Contract Three-Storey Acad Bldg, Buhi Campus.pdf (18 pages,
    4.5MB, all paintImageXObject, 0 words) — the only softcopy the client has is
    scanned. Normal parser must handle it first, S-curve Steps 2-4 are deferred.

    Observed problems on that scan (Images 1-3):
    - Title: OCR returned bold "CHRISTINA JOY CONSTRUCTION AND DEVELOPMENT
      CORPORATION" as Project Title, not the italic "Project: Completion of
      Three-Storey Academic Building, Buhi Campus". Cause: findLabeledValue only
      knew "Project Title|Name of Project" not standalone "Project:" and the
      ALL-CAPS fallback picked the bold contractor over the Title-Case italic.
    - Contract price: preview showed 44.00 (from "PESOS & 44/100 (Php 32,588,531.44)")
      not 32,588,531.44. Cause: asMoney grabbed "44.00" from 44/100; the page
      also has original bid Php 33,596,424.16 before 3% discount, final
      Php 32,588,531.44 after — parser needs context anchor "final Contract
      Amount is …" vs "Original bid amount …".
    - S-curve numbers polluting normal scan: runDocumentAutofill scanned ALL PDFs
      including S-curves (no Document Type filter), so 600k S-curve pesos could
      bleed into normal budget extraction.
    - Other general labels still vary: "Approved Budget for the Contract (ABC)"
      vs "Contract Amount" vs "Project Cost" vs bare "ABC: ₱" — some values on
      NEXT line or NEXT-two-lines. Multi-column scrambling, false footer ₱,
      and 30 sec OCR (18 pages × 10 OCR_PAGE_LIMIT) remain.

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

   STEP 1 — Expand normal PDF keyword robustness (Tier 1)    🔄 IN PROGRESS → CORE DONE, S-CURVE FILTER + TITLE/CONTRACT-PRICE ANCHOR PENDING BEFORE COMMIT
    File: public/js/admin/projects/document-parser-core.js + document-parser.js
    NOTE: This is the normal-doc parser only, NOT S-curve (S-curve is Step 2).
          Implemented 2026-09-04: location restricted to project address,
          budget split ABC vs price, budgetSource/category new, next-two-lines,
          15+6 hardcoded value keywords (hardcoded now, DB later), Option A
          table-lookup. Pending before commit: add standalone "Project:" label
          + contract-price context anchor "final Contract Amount is" vs
          "Original bid amount", and exclude S-curve PDFs from normal scan.

    1a. Label dictionaries (what the parser looks for BEFORE the colon)
        Current labels are in findLabeledValue patterns — expand them:
        - TITLE: add standalone "Project:" (italic "Project: Completion of
          Three-Storey …" in WHEREAS clause, Image 2) plus keep
          project title|name of project|project name|title of project
          (value keywords handle the rest). Demote ALL-CAPS fallback when a
          Title-Case "Project:" line exists.
        - LOCATION: now project-specific only — location|project site|
          site address|project location|barangay|brgy.|project address
          (generic "address" removed 2026-09-04 to avoid Contractor Address).
        - BUDGET: split — extractBudget: contract amount|contract price|
          project cost|total cost|budget ; extractABC: standalone abc|
          approved budget for the contract (with (ABC)). Contract Price is the
          final amount AFTER 3% discount (Image 3 WHEREAS after applying 3%:
          Php 32,588,531.44); Original bid Php 33,596,424.16 maps to ABC or
          ignored — parser must prefer "final Contract Amount is … (Php …)" over
          generic "Php 44.00" from 44/100. Add context anchor
          "final Contract Amount is|WHEREAS.*Contract Amount" first.
          AsMoney must ignore bare "44.00" from "44/100".
        - BUDGET SOURCE (NEW): source of fund|funding source|source of funds|
          budget source|fund source|funding agency|source of financing
        - CATEGORY (NEW): category|project category|project type|type of project|
          nature of project|classification
        - CONTRACTOR: keep contractor|awarded to|winning bidder|proponent|
          company name|firm name|contractor name (already expanded).
        - DATES: add NTP Date|Notice to Proceed|Commencement to startDate;
          keep endDate labels as-is. Make findLabeledValue try same-line,
          next-line, AND next-two-lines (BOQs put value 2 lines below label).
        - Add fuzzy label match (Levenshtein ≤2) for OCR typos
          ("Aproved Bdget", "Contarct Amount") — optional, low priority.
        - S-CURVE EXCLUSION (NEW 2026-09-04): normal runDocumentAutofill must
          filter out S-curve PDFs (!isSCurveCandidate from scurve-import-core.js)
          or skip when Document Type === Schedule — S-curve numbers currently
          bleed into budget/title. Keeps the two parsers from mixing.

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

  STEP 1e — CHS-Savings bundle fixes + intra-file voting (NEW 2026-09-04)  ⬜ NOT STARTED, NO CODE YET
    Context: user tested ONLY `sampledocs/Completed Project/CHS - Savings.pdf`
    (2.4MB scanned bundle: inspection + acceptance + accomplishment + time-elapsed
    reports for same 4-storey CHS project). Result: title short (missing
    "(College of Health Sciences)-Savings"), location/contractor/end-date correct,
    budget 5049.29 (tail of 6,495,049.29), duration 1 (should be 90). The 4 prior
    contract files stay accurate — this step must not regress them.
    User decisions: (a) Revised Contract Amount wins over Original when present;
    (b) keep Best-guess + correction (fill + let user fix); (c) bundle PDFs should
    try ALL pages (recall over precision) with voting to keep accuracy up.

    1e-1. New label synonyms (document-parser-core.js, Tier 1):
        - Duration += `original contract time` (+ `contract time` alone).
          Reason: bundle uses "Original Contract Time:" — current list
          (duration|contract period|contract duration|no. of days|calendar days|
          working days) never matches, so fallback picks stray "1".
        - Start date += `date of effectivity of contract` (+ `effectivity`).
          Reason: bundle uses "Date of Effectivity of Contract:" — current list
          (start date|commencement|NTP|contract start) misses it.
        - Budget += `original contract amount` as Contract Price candidate AND
          `revised contract amount` as preferred override. Rule: if revised found
          use revised, else use original, else existing fallbacks. ABC stays
          separate (ABC ceiling vs price). End date `date of completion` already
          exists — no change (it worked).
    1e-2. Price glue (fix 5049.29 tail):
        - Cause verified 2026-09-04: "Original/Revised Contract Amount" labels do
          NOT exist in code, so the correct one-line value was never read. Parser
          fell through to third fallback "any large Php/P amount" which scans the
          WHOLE bundle top-to-bottom and returns the FIRST large amount — that was
          a different section's split/truncated number (tail 049.29), not the
          contract summary line the user sees.
        - Fix: (i) add the two labels above so the correct line is read first;
          (ii) before asMoney, glue split money tokens across 1-2 lines
          ("6,495,0" + "49.29" with space/comma break → "6,495,049.29") and strip
          gaps from column/cell breaks; (iii) keep Php-prefer + >100k guard so
          44/100 fractions still lose.
    1e-3. ALL-CAPS two-line title join (fix short title):
        - "Project name:" IS already a label (extractTitle:374 + :453) — not a
          missing keyword and NOT a cross-file vote effect (single-file test has
          no vote). Cause is greedy join stopping early on ALL-CAPS split
          ("CONSTRUCTION OF FOUR-STOREY" / "ACADEMIC BUILDING (...) - Savings").
          Fix: allow greedy continuation for ALL-CAPS lines with hyphen break
          (Four- + Storey) until "(...)-Savings" tail, same as Title-Case path.
    1e-4. Pooled global vote for the filled VALUE + per-file winners for the WARNING
        (user revision 2026-09-04: "get all finds in all files"):
        - Today: per file = FIRST match wins (findLabeledValue returns first;
          parseProjectInfo has one value per field). voteParsedFields counts ONE
          vote per FILE, and single-file returns as-is with no vote. So a correct
          value repeated 3x in one bundle loses to a wrong first hit, and a wrong
          first-hit per file propagates to the cross-file vote.
        - Fix (two tracks, same candidate pool):
          (a) VALUE track (what fills the preview): collect ALL candidates per
          field from ALL files into ONE pooled pile (all labeled hits + all
          fallback Php amounts + all duration/date hits, each tagged with
          file/section/page + weight). Score each candidate: frequency across the
          pile first, tie-break (1) labeled over fallback, (2) contract-summary
          section (weight 1.0) over inspection/accomplishment section (0.6),
          (3) full-length money over tail (digit-count). Highest score fills the
          preview. Single-file goes 1/1 passthrough → N/1 voted (e.g., 3x
          6,495,049.29 beats 1x 5049.29); multi-file goes winners-vote → all-finds
          pooled vote (e.g., 3x + 2x = 5 pooled beats a wrong first-hit).
          (b) WARNING track (red mis-upload banner): keep ONE winner per FILE
          (intra-file vote with same weights) so getMismatchInfo can still say
          "File 1 says X, File 2 says Y". Value and warning are computed from the
          same pool but reported separately — accuracy from the pile, accountability
          from per-file winners.
        - Guard: a thick bundle cannot cheat by sheer repetition — weight beats
          count when sections differ (contract 1.0 vs supporting 0.6), and
          labeled beats fallback at equal weight.
    1e-5. Bundle section weighting (supports "try all pages"):
        - Keep reading ALL pages (user choice), tag each pooled candidate with its
          section (nearby lines: `contract amount|contract time|effectivity` =
          contract 1.0 vs `inspection|accomplishment|acceptance|time elapsed` =
          supporting 0.6). Same weights feed both tracks above.
    - Tests: (i) Original/Revised label fixtures (revised wins; original-only
      falls back); (ii) split-money glue fixture ("6,495,0"+"49.29" → full);
      (iii) ALL-CAPS two-line title fixture; (iv) pooled-vote fixture
      (3x correct in file A + 2x in file B beats 1x wrong first-hit per file;
      mismatch banner still lists per-file winners); (v) re-run 39/39 + 4 contract
      files unchanged.
    - No new deps, no vite, S-curve still deferred.

  STEP 1f — CHS-Savings re-test fixes: Contract: title + vote inspector + table tightening  🔄 IMPLEMENTED, NOT COMMITTED (2026-09-04)
    Context: user re-test after Step 1e on real CHS - Savings.pdf bundle:
      title still short (acceptable per user, fix if possible), budget 92025.00
      (NEW wrong, was 5049.29 tail), start/end/duration now accurate.
      User Image 1 (Certificate of Preliminary Acceptance §1): the bundle calls the
      title "Contract: Construction of Four-Storey Academic Building (College of
      Health Sciences) - Savings located at Camarines Sur Polytechnic Colleges
      (CSPC), Nabua ... dated December 9, 2024". "Contract:" was never a title
      label, so that full-tail line was ignored and a shorter "Project name"
      occurrence won. Accomplishment report has a cost-breakdown table whose bottom
      row carries the true total 6,495,049.29; user could not find 92,025 by eye
      (pure scan, Ctrl+F unusable) and asked if the system sums rows (it does not —
      it only picks single numbers; 92025 is one extracted string or OCR misread).
      User requested a console vote log (all finds + winner per field).

    1f-1. Guarded "Contract:" title label + "located at" stop (core extractTitle).
        - New scanContractLabelTitle(): matches /contract\s*:\s*(.*)/ only when the
          line does NOT contain amount|time|price|period|duration|no.|number, and the
          value contains a construction-family word. Short values (<60) join
          continuation lines (Title-Case / value-keyword / ALL-CAPS part) until
          located-at / DESCRIPTION_NEXT_FIELD / hereinafter / WHEREAS, with hyphen-
          break glue. cutAtLocatedAt() splits value at /\s+located\s+at\b/i so the
          location never leaks into the title. Preferred when it yields ≥20 chars
          (acceptance tail beats short Project-name block); otherwise falls through
          to the existing Project-label path unchanged. Project path also cuts its
          inline value and continuation at located-at. Guard tests: "Contract Amount:
          Php ..." and "Contract Time: 90 ..." return null as titles.
    1f-2. Vote inspector (user request).
        - Core: voteCandidatePoolDebug(cands) → { winner, rows:[{value,norm,count,
          score,labeledCount,files}] } sorted score-desc; parseProjectInfoPooled now
          returns _debug { budget, duration, startDate, endDate } alongside winners
          (return shape backward compatible — pooled.budget etc. still strings).
        - Wrapper (document-parser.js): stashes pooled._debug to window._autofillVoteDebug
          and, when window._parserDebug is true, console.groupCollapsed + per-field
          console.table (top 10). Use: console → `window._parserDebug = true` →
          re-upload → copy tables here. Best-effort try/catch, never blocks preview.
    1f-3. Price vote tightening (reproduced 92025 in-test, then fixed).
        - Root cause (verified by new failing-then-passing test): collectBudgetCandidates
          labeled loop gave every "Total Cost P 92,025" table row weight 0.4–0.85 with
          no penalty, and ctxOf ±1 missed the table header for the 2nd+ row (0.85
          neutral), so 3x table (1.05) outvoted 1x contract summary (1.0).
        - Fix: (i) getSectionWeight table 0.6→0.4 for accomplishment/quantity/
          breakdown/table contexts (contract still 1.0, other supporting 0.5);
          (ii) labeled money without any contract word nearby (original/revised/
          contract amount|price|time, effectivity) halves weight (table row 0.4→0.2,
          neutral row 0.85→0.425); true bottom-row "Total Contract Amount ..." keeps
          1.0 via the contract-word exemption; (iii) unlabeled fallbacks 0.8→0.5
          multiplier; (iv) budget ctxOf widened ±1→±2 so rows see the table header.
          Result: 1x summary (1.0) beats 3x table (0.6). "It never sums rows" stays
          true — pooling sums WEIGHTS (confidence), never money values.
    - Tests (+3 Step 1f fixtures, core 27/27): Contract:-label tail with located-at cut;
      Amount/Time guard nulls; pooled prefers 1x summary over 3x table + _debug shape
      (winner first row) + getSectionWeight contract 1.0 / table <0.6. Full JS 47/47
      (core 27 + scurve 20). No PHP touched.
    - Next: user re-uploads real bundle with `window._parserDebug = true` and pastes
      the budget table here if 92025 (or another figure) persists — the log will show
      the exact source line/section instead of guessing. Short title acceptable fallback.

  STEP 1g — Choice B (ask-user, ANY field) + inspector excerpts FIRST  🔄 IMPLEMENTED + tested (2026-09-04), NOT COMMITTED per user
    Context: real-bundle inspector output (user pasted 2026-09-04):
      budget winner 92025.00 (count 2, score 2, labeled 2) beats 6495049.29
      (count 1, score 1, labeled 1) and 5049.29 (1/1/1). Duration 90, start
      2024-12-09, end 2025-03-09 all single-find winners. So the two 92025 hits are
      FULL-weight labeled finds — weight tightening alone cannot stop them.
      User decisions (plain words): (a) Choice B for now — when two trusted values
      disagree, do NOT silently pick the bigger one; fill best guess but pop a note
      asking the user to pick. Choice A (automatic) or both (confidence-based: auto
      when clear, ask when close) may come later. (b) Excerpts FIRST, page numbers
      only if excerpts cannot pinpoint the source. (c) Choice B for ANY voted box
      (budget/duration/start/end), not price-only — automation goal unchanged: the note
      appears ONLY on genuinely ambiguous files, clean files see nothing extra.
      User Q answered: single-file tests are PART of the problem (no cross-file pile-up
      to bury repeats — proven by the earlier 3-file majority test), but NOT the whole
      problem: full-weight labeled repeats need the evidence rule below regardless.
      Codebase study (2026-09-04, read-only): candidates {value,labeled,weight,fileName}
      (core:927+) and debug rows {value,norm,count,score,labeledCount,files} (core:1066)
      carry NO pattern/excerpt/page. Page numbers are LOST at extractTextFromPDF merge
      (document-parser.js:273 fullText+=pageText) — never stored, mirroring needed from
      scurve-pdf-parser.js:34-40 itemsByPage pattern if pages are ever wanted. Preview
      modal shell is components/autofill-preview-modal.blade.php (rows built dynamically
      by showAutofillPreview :389-465; NO per-field note areas exist — mismatch banner is
      created dynamically at :441-448, and the Choice B note follows that same pattern).
      Related notes (read before building): others(notes)/pdf_parser_contract_amount_
      resolution_plan.md (evidence hierarchy; "bigger wins" must NOT be main rule) and
      others(notes)/contract_amount_duration_accidental_discovery.md (37-project table is
      LOW priority secondary signal only; do NOT build now).

    1g-1. Inspector excerpts FIRST (core only, no extraction change).
        - Each pooled candidate gains: pattern name (labelRes entries get name: e.g.
          'revised'/'final-anchor'/'generic'; fallbacks 'paren-php'/'net-total'/'p-prefix')
          + ±2-line source excerpt (from the collecting loop's own norm window).
          Debug rows gain {pattern, excerpt}. Pure in core (testable), rendered via the
          existing _parserDebug console.table (+ window._autofillVoteDebug).
        - User re-runs once and pastes the budget table → Case A (near-identical excerpts
          → dedupe: count once) vs Case B (two truly different labeled lines → 1g-2).
        - Page numbers DEFERRED: only if excerpts cannot pinpoint (requires
          extractTextFromPDF per-page return + pooledDocs page tagging).
    1g-2. Choice B disagree handling for ANY pooled field (budget/duration/startDate/endDate).
        - Trigger per field: pooled top-2 rows both labeled (labeledCount>0), different
          norms, winner score < 2× runner-up. Computed in the pooled-override block
          (document-parser.js:112-139), stored as window._autofillDisagree per field.
        - Behavior: preview fills the current winner BUT renders an amber note under that
          field's row: "I found two different {label}s — X and Y. Please pick the right
          one." with both values as one-click buttons writing into previewInput-<id>;
          input stays editable. Never blocks Apply (same non-blocking pattern as the red
          mis-upload banner). Title/contractor/location keep existing behavior (red banner
          already covers them cross-file). Silent when one clear winner exists.
        - Future (NOT now): Choice A / both — auto-pick when gap LARGE (≥3×) or
          contract-word label beats table label outright; historical plausibility
          (amount-vs-duration table) only as secondary warning, never the decider.
    - Tests: (i) pattern+excerpt present in _debug rows; (ii) disagree fixture per field
      (1x 6.4M labeled vs 2x 92k labeled → winner filled + disagree flag true);
      (iii) agree fixture (single clear winner → flag false); (iv) full JS suite green
      (47/47 baseline) + 4 contract files + bundle browser check unchanged.
    - No new deps, no vite, S-curve still deferred.

  STEP 1h — Date-ghost money fix (92025 diagnosis PROVEN by excerpts)  🔄 IMPLEMENTED + tested (2026-09-04), NOT COMMITTED per user
    Diagnosis (user pasted expanded inspector 2026-09-04 — excerpts WORKED first try):
      budget rows: 92025.00 patterns ['revised','generic'] excerpt '2. Date of Effectivity
      of Contract December 9, 202… | 5. Original Expiry date March 9, 2025 | 6. App' (ONE
      distinct excerpt, count 2 = Case A duplicate-ish: same neighborhood matched by two
      label patterns); 5049.29 pattern ['generic'] excerpt '…6.495,049.29 | 4. Revised
      Contract Amount XXX …'; 6495049.29 pattern ['generic'] excerpt 'Mode of
      Implementation : By Contract | Contractor … tem Gost % of Amount of
      Accomplishment | No Tota' (true total, accomplishment-table bottom row, alone).
      User's call CONFIRMED: 92025 is the start date (December 9, 2025) misread as money.
      Precise mechanism — Step 1e-2 glueMoneyFragments /,\\s+(\\d)/ turns the DATE comma
      in '9, 2025' into '9,2025' → asMoney 92025.00; 'revised'+'generic' both match around
      it → full-weight labeled ×2 → beats the lone true 6,495,049.29 (2-vs-1).
      Companion find: 5049.29 comes from OCR period-thousands '6.495,049.29' (excerpt
      verbatim) which asMoney cannot parse, keeping only the tail.
      Page numbers NOT needed — excerpts pinpointed everything (deferred stays deferred).

    1h-1. Tame the comma glue (the actual bug; core glueMoneyFragments).
        - Glue comma-space ONLY for thousands groups: /,\\s+(\\d{3}(?!\\d))/ — '6, 495'
          still glues, '9, 2025' (4-digit year) never does. Other glue rules already
          require a decimal point, so dates were never at risk from them.
    1h-2. Never take money from a date (belt-and-suspenders; collectBudgetCandidates).
        - Skip a budget candidate when its value text parses as a calendar date AND carries
          no money marker (Php/₱/P-prefix/pesos/amount). A future glue misfire can never
          mint a price from a date again.
    1h-3. Period-thousands shape (asMoney, money-marked strings only).
        - In Php/₱-marked figures with mixed separators ('Php 6.495,049.29'), treat the LAST
          dot as the decimal, strip other dots/commas appropriately → full 6495049.29.
          Ordinary sentences untouched (requires the money marker).
    - Tests: (i) 'December 9, 2025' yields NO budget candidate; (ii) 'P 6, 495,049.29'
      still glues full; (iii) '(Php 6.495,049.29)' reads full; (iv) pooled 1x-summary vs
      2x-date-ghosts → 6495049.29 winner AND _disagree.budget null (no amber note on a
      solved case); (v) full JS suite green + 4 contract files + bundle browser re-check.
    - No new deps, no vite, S-curve still deferred.

  POST-1i ROADMAP — Learning loop, then AI fallback (agreed with user 2026-09-04, NOT STARTED)
    Sequence: finish 1h/1i bundle accuracy → learning loop (tested on the bundle) → AI
    fallback LAST. Rationale banked: the loop feeds on the stable preview-correct flow;
    the AI trigger list is drawn from whatever the rules provably cannot solve after 1i +
    learning; cost/risk ascend free/offline/reversible → quota/latency/data-leaving.

    A. AI fallback for NORMAL documents too (user approved, scope note).
        - Sensitivity finding (user confirmed): contract/supporting papers are public-
          bidding records — contractor names, business addresses, working emails exist to
          be visible (postings, awards, signboards). Real sensitivities (personal IDs, bank
          numbers, private signatures) do not live in these papers. Design stays slice-only
          (1–2 confusing pages as a small image, never the whole file) and unsure-only
          (local reader admits defeat first). Same gates as the S-curve Tier 3 slice.
    B. Learning loop — HYBRID (user approved; neither pure-auto nor pure-manual).
        - System silently COLLECTS label→field pairs from every preview correction; a pair
          goes LIVE only after 2–3 independent confirmations across files OR admin one-click
          in a small review list; any live entry deletable in one click. Auto gathers,
          humans/repetition trust. Never learns from unresolved-disagree files until the
          user picks (else ambiguity becomes "knowledge").
        - Replaces the hardcoded TITLE/CONTRACTOR arrays' frozen fate (§11 DB-driven item):
          first weird file costs one correction, every later file is free.
    C. Evaluation without future files (user's "can't pre-test" concern — answered).
        - Synthetic variants: paraphrase the 5 known contracts' labels automatically and
          require the rules to still hit (stress-test without waiting for real files).
        - Every new real file either passes (rules generalize — most will) or becomes one
          labeled fixture + one learning-loop seed. Miss rate ratchets down per file.
    - No new deps decided yet; S-curve Steps 2-4 remain deferred behind bundle accuracy.

  STEP 1i — Positive targeting: placeholders + same-line priority + TOTAL CONTRACT AMOUNT  🔄 IMPLEMENTED + tested (2026-09-04), NOT COMMITTED per user
    Context: user's two table photos of the CHS bundle — (1) numbered summary list (1. Original
      Contract Time 90 days … 3. Original Contract Amount Php 6,495,049.29 / 4. Revised
      Contract Amount xxx / 5. Original Expiry date March 9, 2025 … 12. Date of Completion
      March 9, 2025); (2) accomplishment-table bottom row TOTAL CONTRACT AMOUNT 6,495,049.29
      (Item Cost column) with header 'Contract Amount: Php 6,495,049.29' printed above the
      table. Table columns: Item Cost | % of Total | % Accomplished (Prev/This/ToDate) |
      Amount of Accomplishment (Prev/This/ToDate); the 6,495,040.32 lookalike is the
      Amount-to-Date progress figure (8.97 less — rounding residue or OCR noise), NOT a
      competing price. Year-block + period-thousands already built as 1h (not repeated here).

    1i-1. Placeholder rule (ghost side; collectBudgetCandidates labeled loop).
        - A value slot reading xxx / none / n-a / – / blank (case-insensitive, punctuation-
          tolerant) resolves to EMPTY for that label occurrence — do NOT slide to next /
          next+2 lines. Item 4 (Revised: xxx) then yields nothing and can never reach item 5's
          date. Encodes the business rule: xxx on Revised = "no change, use Original".
    1i-2. Same-line beats slid (positive targeting; candidate weights).
        - A value found on its label's OWN line outranks one borrowed 1–2 lines below:
          same-line labeled hit keeps weight 1.0 (× section), slid hit ×0.8. Item 3's
          Php 6,495,049.29 then beats any slid date/figure outright, before frequency counts.
    1i-3. TOTAL CONTRACT AMOUNT first-class (table bottom rows; collector + weights).
        - Own named pattern ('total-row') for /total\s*contract\s*amount/i lines: only the
          row's FIRST money figure (Item Cost column) becomes a candidate — later figures
          (percentages, Previous/this-Period, to-Date …040.32) never do. Summary-level trust
          (contract weight 1.0, exempt from the table halving since the label itself carries
          the contract word). The printed header 'Contract Amount: Php …' above such tables
          is already caught by the generic label and corroborates for free.
    - Tests: (i) 'Revised Contract Amount: xxx' + date below → NO candidate (no slide);
      (ii) same-line 6.4M beats slid lookalike even at equal counts; (iii) total-row line
      yields exactly its first figure; (iv) …040.32-style to-Date figure never outranks Item
      Cost total; (v) full JS suite green + 4 contract files + bundle browser re-check.
    - No new deps, no vite, S-curve still deferred.

  STEP 2 — Excel hardening + Summary-Row Validated read  🔄 IN PROGRESS (2a planned 2026-09-08, build on go-ahead; 2b/2c DEFERRED until a second real contractor Excel exists — user decision 2026-09-08: no evidence-driven behavior changes without evidence)
    File: app/Services/SCurveImportService.php
    (also mirrors to public/js/admin/projects/scurve-pdf-parser-core.js for PDF)

    2a. Expanded anchor synonyms (rule-based, not positional) — ✅ BUILT on merge15-baseline 2026-09-08 (code, NOT committed; blinding untouched — parser track, no JS/PHP-parser overlap).
        - isWeightLabel: added "weighted" + "allocation" ("weightage" was already present —
          verified, not duplicated). Fixture locks all three variant headers to the same curve.
        - isDescriptionLabel: UNCHANGED — "PARTICULARS" and "WORK ITEMS" were already present
          (fixture locks them against regression instead of duplicating). "ITEM NO."
          deliberately NOT added: isItemLabel() claims it first (elseif order) and it is
          semantically an item-code column; the "NO. OF DAYS" false-positive stays impossible
          via the ^$ anchors (re-verified by suite).
        - NEW isCumulativeLabel() + meta['cumulativeRow'] (first matching data-row, 1-based
          sheet row; null when absent). Detection matches the FULL row text, not descCol only
          — caught pre-green: BUHI keeps "% ACCOMPLISHED" labels in column A (outside descCol
          C), so descCol-only detection reported null on the real file. Widened before green;
          BUHI now reports cumRow=89. Curve untouched by construction (flag read only by 2b).
        - Tests: +3 fixtures (weight synonyms ×3 headers, PARTICULARS + activity/code intact,
          PLANNED CUMULATIVE % detected at row 13 with identical curve). SCurveImportTest 15/15.
          BUHI re-run byte-identical (12mo/59act/32,588,531.43/w100/M1 1.12/M4 10.59/M7 28.64/
          M10 68/M12 100/warnings []) + cumRow=89. Full composer suite: 1029 passed / 7 failed =
          the same 7 pre-existing merge-15 failures (clean-tree verified) — zero regressions.
        - No commit without user OK. 2b/2c stay deferred pending a second real contractor Excel.
        Rationale (user-approved 2026-09-08): detection-only, near-zero behavior risk — turns
        future "No S-curve table found" 422s into parsed curves. BUHI baseline stays
        byte-identical (its labels already match; wider alternatives never fire on it).
        2b (Validated numbers) + 2c (SUM-vs-MAX) change emitted values and wait for a real
        second file — synthetic-only number branches risk solving layouts nobody sends.

        Touchpoints (all in app/Services/SCurveImportService.php; restudy 2026-09-08):
        - isWeightLabel (:287-290): add "weighted", "allocation", "weightage" to the
          \b(weight|weightage|share|gravity|distribution|percent(age)?)\b alternation.
        - isDescriptionLabel (:282-285): add "Item No.", "Particulars", "Work Items" to the
          description alternation. Guard: "Item No." must not reintroduce the "NO. OF DAYS"
          false-positive codeCol (anchored item regex already guards — re-verify by suite).
        - NEW isCumulativeLabel(): /cumulative.*%|planned.*cumulative|%.*accomplished/i for
          summary rows ("CUMULATIVE % ACCOMPLISHED", "PLANNED CUMULATIVE %"). Detection-only
          in 2a (feeds the 2b reader later); never required (CONSCHEDSAVINGS.pdf has no label —
          pattern %-row fallback stays the fallback).
        - Scoring (betterHeader :198-201, hard gate :183 descCol + ≥2 monthCols): new synonyms
          ride the existing score (hasItem/hasWeight/hasContract/monthCount) — no gate change.
        - Explicit NON-goals for 2a: no buildMonths() number changes, no finalizeHeader span
          changes, no new warnings, no UI/controller/route/migration changes, no PDF changes.

        Tests (tests/Feature/SCurveImportTest.php, in-test workbooks like the existing 12):
        - (i) weight-synonym workbook ("WEIGHTED %"/"ALLOCATION" header) parses the same curve;
        - (ii) description-synonym workbook ("PARTICULARS"/"ITEM NO." + code col) parses with
          activities intact;
        - (iii) cumulative-label row detected (assert via meta or a debug accessor — NOT via
          emitted curve, which must not move in 2a).
        Verification: full 12-test baseline green + 3 new green; real BUHI sample re-run
        byte-identical (1.12/6.74/21.09/49.73/100, contract 32,588,531.43, 59 activities,
        warnings []); composer suite zero-regressions (7 pre-existing merge-15 failures stand).

    R0. KNOWN LAYOUT FAMILIES (recorded 2026-09-08 — formats must be KNOWN and distinguished,
        user decision; BUHI vs Roof share one importer but are different families)
        - FAMILY-B (BUHI, working): 12 months as fortnight triplets (15/15/5-day cols I:AF →
          12 paired spans); `Month 1..12` labels; DESCRIPTION / CONTRACT AMOUNT / % WEIGHT;
          59 detailed activity rows; quarterly %/cost summary block. Reference:
          sampledocs/Updated-S-Curve-3-Storey-Bldg.CSPC-Buhi.xlsx (git-ignored, manual QA only).
        - FAMILY-R (Roof, PARSING since R2a 2026-09-08 — was a 422): 7 whole-month groups (4 display cols each; `30 days`×6 +
          `7 days` = 187 days, merged group headers row 19); months as ORDINALS `1st.`–`7th`
          (AM19:AS19, single-column spans); `DESCRITPION` typo header (B18, merged B18:C19);
          `Unit Cost/Contract Amount` (F); NO weight column (fractions G:AG, pesos AM:AS);
          ~10 group-level LOT activities (rows 21-30); 6-row summary block (31 TOTAL, 32 %,
          33 Commulative [%], 34 Cash Flow [pesos], 35 Project Duration, 36 Commulative [days]).
          Verified curve: 7mo 6.32/24.31/42.3/59.71/77.03/99.14/100, contract 2239599.72,
          browser-proven accurate end-to-end. For an ONGOING project per client — still a
          PLANNED curve (all values formula-chained to plan data; PMU records actuals
          separately via monthly inspections).
        - Roof engine/display split (user-confirmed 2026-09-08): AM:AS are calculation-engine
          columns the contractor HIDES (user: hidden in the working file) — the human-visible
          table is the G:AG display grid (merged 4-col groups linking =AM31/=AN31…,
          =G31/F31…=, running =K32+G33… sums). Hidden ≠ unreadable via API, but the narrow
          forensic dump skipped AP:AS + never read G:AG and falsely reported "summary empty".
          Lesson banked: dump the whole grid (both tables), not assumed columns.
        - SUMMARY SECTION CONFIRMED (user Q 2026-09-08): yes — the bottom block in BOTH files
          (TOTAL + % + cumulative + COST/Cash Flow + durations) is THE summary section. BUHI:
          R88 TOTAL / R89 % / R90 cumulative / R91 COST / R92 cumulative cost. Roof: R31 TOTAL /
          R32 % / R33 cumulative / R34 Cash Flow / R35 duration / R36 cumulative days.
          Table proper ~A-AU; A10:IR82 width is title-block artifact. Reference:
          sampledocs/CSPC-Rehabilitation-of-Roof-at-Academic-Building-II-S-CURVE.xlsx
          (git-ignored, manual QA only). Same contractor ⇒ same format assumed (user, evidenced
          by matching hard copy) — supports contractor-keyed parser_templates later.
        - Rule going forward: patterns from other paths (PDF cumulative rows, weekly spans) may
          INFORM Excel design but never SUBSTITUTE Excel bytes — only same-path bytes prove.

    R1. RESEARCH: HOW OTHER SYSTEMS HANDLE INCONSISTENT EXCEL FORMATS (2026-09-08, web sources)
        Finding: nobody standardizes contractor S-curve FILE INGESTION (all S-curve literature
        is EVM methodology + templates). Inconsistent-Excel ingestion is a solved-shape problem
        elsewhere, converging on one pattern — Power Query's mapping-table flow is the cleanest
        documented instance (TrumpExcel combine-CSVs guide):
        1. Canonical schema FIRST (ours: {months, meta}, Option A no-migration — already this).
        2. Mapping table, human-mapped ONCE (extract all headers → person maps variants to
           canonical names → rename + combine with MissingField.Ignore). == parked
           parser_templates / unknown-header-capture direction (accuracy plan §11); contractor
           stability (FAMILY-R same-format evidence) makes per-source mappings durable.
        3. Fuzzy match WITH a threshold for typos (Power Query fuzzy merge, Fuzzy Lookup Add-In,
           Levenshtein) — plus explicit guidance that BRIDGE TABLES cover what algorithms can't
           guess. == our ≤2-distance choice + learned mappings as complements. Validates both.
        4. Tolerant combine (missing fields ignored per file, batch never fails) — == our
           per-file vote + warnings behavior.
        Rejected for PMU: platform answers (Mastt/monday "stop using spreadsheets" — PMU cannot
        mandate contractor tooling); heavy cleaners (OpenRefine/Talend — overkill for 2 families).

    R2a. ROOF-FAMILY SUPPORT (FAMILY-R) — ✅ BUILT + BROWSER-PROVEN on merge15-baseline 2026-09-08 (committed; blinding untouched — S-curve track has no parser overlap).
        - Detection: headerFuzzyMatch() (Levenshtein ≤2, words ≥6 chars, anchored full-cell)
          wired into isDescriptionLabel (DESCRITPION ✓) + isCumulativeLabel (Commulative ✓);
          ordinal-month branch 1st.–12th in matchMonthLabel (AM:AS ordinals ⇒ single-column
          spans, no double-count, no 2c dependency). Far words (NO. OF DAYS) stay rejected —
          locked by the unknown-headers-still-422 fixture.
        - Curve: monthly reads from the AM:AS peso series (formulas resolve via the existing
          engine); contract from F Σ via TWO new orphan rules proven necessary by the cell dump:
          (a) desc-less contract-only rows immediately after a counted activity attribute to the
          running total (roof even-row subtotals; tight: month-less only, chain broken by any
          skip/TOTAL/non-activity); (b) TOTAL label outside descCol falls back through full-row
          text (hyphen-guarded so Sub-Total never matches; requires contractAmt present).
        - Skip expansion: %, Cash Flow (real pesos), Project Duration, Commulative — otherwise
          the cash-flow row absorbs as an activity (diagnosis-proven distortion).
        - Cell-dump findings (banked): odd rows = labeled formula rows (=fraction × subtotal),
          even rows = desc-less F subtotals, R30 AU = section total, R31 F = =SUM total
          (2239599.72), R32 = month indices, R34-36 labels in F. Shared rowTexts() helper for
          full-row checks (cumulative + TOTAL use one trimming rule).
        - Proof: roof file parses to a REAL curve (7mo 6.32/24.31/42.3/59.71/77.03/99.14/100,
          contract 2239599.72, 5 acts, warnings [], cumRow=33 — was a 422 before).
          BUHI byte-identical incl. full 12-value curve + cumRow=89. SCurveImportTest 19/19
          (+2 orphan fixtures). Full suite 1033 passed / 7 failed = same 7 pre-existing
          merge-15 failures — zero regressions. Scratch verification scripts removed.
        - BROWSER PROOF 2026-09-08 (user, detail-tab Import — button/controller/UI unchanged):
          roof 7 months Jan–Jul 2026 with exact service-verified values + per-month counts
          (4,4,4,4,3,4,3), user verdict "accurate"; BUHI 12 months Jan–Dec 2026 with full
          activity distributions (8,11,12,14,19,20,32,34,38,43,41,41) and banked curve values,
          user verdict "still accurate". R2a CLOSED end-to-end (code → fixtures → real files →
          browser, both families).
        - No commit without user OK. Browser proof (detail-tab Import on the roof file) is the
          user's step — button/controller/UI needed zero changes.
        - Detection: fuzzy header match (≤2 Levenshtein, anchored full-cell, header matchers
          only) so DESCRITPION → description; ordinal-month branch /^(\d{1,2})(st|nd|rd|th)\.?$/i
          → month index (cap 1..12); existing two-row merge pass covers the 18–19 block.
          Fixes the exact 422: hard gate failed BOTH disjuncts (descCol null via typo +
          0 monthCols via ordinals). Far words (NO. OF DAYS) stay rejected by distance.
        - Curve: monthly reads from the AM:AS peso series (gap-1 ordinals ⇒ single-column
          spans, no double-count, no 2c dependency); contract total from F Σ (weight optional
          path already exists); G:AG fraction cells NEVER summed as pesos.
        - Skip expansion (diagnosis-proven necessity): `%`, `Cash Flow` (real pesos!),
          `Project Duration`, `Commulative` rows excluded as summary vocabulary — otherwise the
          cash-flow row absorbs as an activity and distorts the curve. isCumulativeLabel covers
          the typo'd rows (fuzzy) for 2b later.
        - Proof: synthetic workbook transcribed from roof shapes (typo header, 18–19 block,
          ordinals, AM:AS pesos, full 6-row summary block) asserting the correct 7-month curve +
          all summary exclusions + fuzzy near/far cases; BUHI byte-identical; full suite green
          (7 pre-existing stand). Roof file stays the manual QA reference.
        - Forward links: roof becomes layout family #2 in the future parser_templates design
          (contractor-keyed — same-format assumption now evidenced); PDF cumulative patterns
          inform 2b thinking but never substitute Excel bytes (R0 rule).

    R3. BUHI SUMMARY-BLOCK FORENSIC + 2b REDESIGN (recorded 2026-09-08 — read-only cell
        inspection via PhpSpreadsheet getValue/getCalculatedValue/getDataType, no files touched)
        - Verdict CORRECTED: the old "author-entered static values" claim is WITHDRAWN. The R89-92
          quarterly block is FORMULA-computed from the same activity table (R88 column SUMs via
          R91 six-column group sums and R92 running totals). Nothing is separately entered.
        - The 6.5-vs-6.74 mechanism (fully traced): summary fractions divide by D88 =
          ₱33,596,424.16 (the ORIGINAL pre-discount bid); the importer divides by the FINAL
          ₱32,588,531.43. 6.74 × 0.97 = 6.54 ≈ 6.5 — the ENTIRE gap is the 3% discount, plus
          coarser 6-column groupings (I..N) vs paired spans. The "97% endpoint" is the same
          artifact (32.58M ÷ 33.6M = 0.97 — can never reach 100 by construction, not shortfall).
          User's recalled "0.07/7%" is the cell display rounding; "6.5" the true value — both right.
        - Q1 anatomy: R89 % row and R90 cumulative row coincide at 0.0653 (cumulative of the first
          group equals the group); they diverge after (period 6.5/13.9/27.8/48.8 vs running
          6.5/20.5/48.2/97). Ours (6.74 month-3 cumulative) is a third recipe — paired sums over
          the final contract.
        - User hypotheses banked: (1) actuals hypothesis — REJECTED for this file by the formula
          chains (real actuals would be static like accomplishment reports); the labels say
          ACCOMPLISHED but the numbers are plan-derived. (2) Series insight — ACCEPTED as the
          decisive design principle: planned (activity table) vs accomplished (author block) are
          different series, and baseline setup must build from PLANNED only.
        - PLANNED-SERIES VERDICTS (user-confirmed context): BUHI = planned (whole workbook chains
          to plan data); Roof = planned (time-phased formula distributions; ongoing-project status
          doesn't make it actuals — PMU records actuals via monthly inspections). Taxonomy: TWO
          PLANNED-S-curve families, zero actuals files in hand. Importer stays a planned-only
          reader. (Future idea, unplanned: importing genuine actuals blocks as progress records —
          progress entry, NOT baseline import.)
        - 2b REDESIGNED (user decision 2026-09-08: reference + warn — TRIPLE-GROUNDED: wrong
          denominator [math] + coarser groupings [precision] + accomplished≠planned [workflow]):
          emit the activity-derived plan UNCHANGED; attach the summary series as reference data
          in meta + warn past 1.0% with wording naming the cause ("author block tracks
          accomplished %, baseline kept from planned activities"). The threshold now flags
          worth-a-look gaps instead of arbitrating a winner. The old "summary wins" design is
          abandoned — it would bake a denominator error (and possibly actuals) into the baseline.
        - Deferred tag on 2b stands, reason updated: awaits build go-ahead, NOT a missing file
          (BUHI is its own mismatch evidence).

    2b. Summary-Row Reference + Warn (REDESIGNED 2026-09-08 — was "Validated adopts summary"; see R3. Reference-only per triple-grounded workflow reason) — ✅ BUILT on merge15-baseline 2026-09-08 (code, NOT committed; curve behavior unchanged by construction).
        Order in buildMonths() (plan UNCHANGED, reference attached AFTER final numbers):
          1) Months computed exactly as before (paired SUM → monotonic clamp → force-last-100).
          2) readSummaryReference(): prefer a /cumulat|commulat/ row, else the detected
             cumulativeRow; per month span take the first numeric cell; attribute each value
             to the last month before the next summary value (BUHI: I in span 1 → month 3);
             fractions (< 1.5) scale to percent; negatives skipped.
          3) meta['summaryReference'] = [{month, summary_percent, activity_percent, gap}];
             gaps past 1.0 push the cause-naming warning (baseline kept from planned activities).
             Files without a readable block report [] with zero new warnings (roof: summary cells
             empty → []; %-only period rows never feed the cumulative comparison).
        BUHI live result (the reviewable output): curve byte-identical; reference
        [m3 6.53/6.74/0.21 silent, m6 20.46/21.09/0.63 silent, m9 48.24/49.73/1.49 WARN,
        m12 97/100/3 WARN]. Tests: +2 fixtures (warn/silent branches incl. the 1.0 boundary +
        fraction scaling; no-summary file stays []). SCurveImportTest 21/21. Full suite 1035
        passed / 7 pre-existing failures — zero regressions. No commit without user OK.
        R2b-GRID (display-grid fallback) — ✅ BUILT on merge15-baseline 2026-09-08 (code, NOT committed).
        Roof's cumulative VALUES live in the G:AG display grid (merged 4-col groups linking
        =AM31/=AN31…, =G31/F31…, running =K32+G33… sums), NOT under month-span columns —
        forensics: R33 = 6.32/24.31/42.30/59.71/77.03/99.14/1.0, R34 running pesos, R35/36
        days→187; AM:AS engine columns intentionally empty there. Rule: (a) values under
        month spans first (BUHI path, unchanged); (b) else collect the cumulative row's
        numerics in column order and, ONLY on exact count match with month spans, pair
        positionally. Mismatched counts stay honestly empty; no curve moves either way.
        SUMMARY FALLBACK (valueless activities + valued summary) — ✅ BUILT same batch:
        adopt summary as curve ONLY when activity math absent (lastNonZero===0) with ≥2
        reference points (single point is not a curve); re-clamp + force-100 (controller
        demands it); curveSource meta flag; provenance + jumble warnings; reference entries
        nulled (no self-comparison); gap warnings suppressed without activity math.
        Disagreement never adopts. Proof: S-curve 24/24; BUHI + roof byte-identical;
        full suite 1038 passed / same 7 pre-existing — zero regressions. No commit w/o OK.
    UX-2b (toast drop + in-modal note + plain wording) — ✅ BUILT on merge15-baseline 2026-09-08 (code, NOT committed; user-reported gap: warning invisible inside modal + toast reads as error).
        - Toast: scurveShowWarnings no longer toasts or renders the beside-button box (stale-box
          cleanup only); success toasts untouched. Import success paths stash
          window._scurveImportWarnings instead (both entry points).
        - Modal: renderSetupImportNotes() renders the stash below the frozen-baseline notice in
          openSetupBaselineModal for import-preloaded months only (manual opens quiet);
          textContent-only (no HTML injection); cleared with the stash on hidden.bs.modal
          (covers close AND save — single listener).
        - Wording (user-approved plain): "Heads-up (Month 9): the file's own summary says
          48.24%, but its activity rows add up to 49.73% — the summary was likely computed
          against a different total. Your baseline below uses the activity math — no action
          needed." Fixtures updated to the new text. BUHI/roof re-verified identical curves
          with the new wording live. Awaiting user browser proof (toast gone, note in modal).
        - FIX 2026-09-08 (missing note in browser — root cause, mine): the DETAIL flow called
          openSetupBaselineModal() BEFORE the stash assignment (wizard order was correct),
          so the modal rendered against an empty stash. Fixed by moving the stash above the
          open call in initProjectDetailSCurveImport. Lesson: stash-then-open ordering is part
          of the pattern, not incidental.
        - FIX 2026-09-08 (stale-JS ghost class): detail tab + project-list blades loaded the two
          touched scripts via bare asset() with no ?v= (the $jsv filemtime closure lived only in
          the wizard modal). Added the same one-liner + switched those tags
          (baseline-schedule.blade.php both files; index + admin/projects setup-modal tag), so
          edited JS invalidates browser caches on every change. view:cache clean; 24/24 S-curve
          tests; both real files re-verified identical. Awaiting user browser re-proof.
        - BROWSER PROOF 2026-09-08 (user): in-modal import-notes block renders correctly on the
          detail-tab flow. UX-2b CLOSED end-to-end (moved warning + dropped toast + plain wording
          + versioned scripts + ordering fix).
        (Supersedes the adopt-branch text below, kept as history of the abandoned design.)
        --- ABANDONED adopt-branch text (kept for history; DO NOT build) ---
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

    2c. Single-column month fallback — ⏸ PARKED until a paired-header/lone-body file exists (user decision 2026-09-08; restudied same day)
        - Precise file spec: header infers PAIRED spans (labels 2 apart or merged) BUT body
          carries each month's FULL peso in ONE cell of the pair (other mostly empty) +
          a couple of full+full duplicates + a TOTAL/contract anchor. Neither family has it
          (BUHI paired/paired; roof single/single) — restudy proved pure lone-style needs no
          rule (SUM≡MAX); only MIXED files diverge (SUM double-counts duplicates, MAX halves
          BUHI-class rows). Hence the file-level >70%-empty gate — whose threshold is
          INVENTED until a real file calibrates it.
        - Risk verdict (user-accepted): building now is riskier than waiting — the branch sits
          on the hottest path (month summation touches every file); silent curve corruption
          (halving/doubling) is the worst failure class; tests would re-assert our own guess.
          Design recorded, one batch when the file arrives.
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
    → SUPERSEDED as the build reference by textfiles/document_parser_ai_fallback_plan.md
      (restudied 2026-09-08: normal-doc first, blinded-ABC excluded, learn-everything + source
      badge, auto weakest pages). This section below stays as history; build from the AI file.
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

  2026-09-04 — Normal-doc-first pivot + scanned contract failure (code + plan updated, not yet committed).
    - User confirmed: Step 1 is normal-doc parser only, NOT S-curve — normalize
      S-curve Steps 2-4 deferred until normal accuracy re-tested.
    - Location is project site (CSPC), not contractor address — restricted
      extractLocation to project-specific labels (removed generic "address").
    - Tested scanned Contract Three-Storey Acad Bldg 18-page 4.5MB: OCR auto
      switched but took ~30 sec (18 pages, 10 OCR_PAGE_LIMIT, scale 2, eng),
      preview showed Project Title = CHRISTINA JOY CONSTRUCTION… (bold) not
      "Completion of …" (italic Project: …). Contract Price 44.00 from
      "44/100 (Php 32,588,531.44)" and ABC confusion (original bid 33,596,424.16
      before 3% vs final 32,588,531.44 after). Added to §3.2 and queued for
      Step 1: standalone "Project:" label, contract-price anchor "final Contract
      Amount is …", S-curve exclusion from normal scan, normal-first priority.
    - Plan status updated to IN PROGRESS (Step 1 core implemented on branch
      phase-5-parser-accuracy); S-curve deferred per user.

  2026-09-04 — Plan adjustment committed 99dbe09 + new fixes planned (not yet committed as new code).
    - User: "maybe we should start with the normal doc accurance improvements first
      before the s curve." Agreed — Step 1 is normal-doc only (core done), Steps 2-4
      are S-curve and remain deferred.
    - Greedy title fix queued: "Project: Completion of Three-Storey" split across
      two printed lines (27 ReportLab lines y547→y536) — current findLabeledValue
      returns only "Completion of Three-Storey" and stops. Need greedy join until
      "," or "hereinafter" / "to be completed within". Contractor fix queued:
      strip " (hereinafter called the …)" tail (currently "(hereinafter called the"
      leaks into preview "THE (THE ()"). Both identified from contract_test_sample.pdf
      (ReportLab 28KB 1 page 91 items) vs original scanned (0 items, single reflow
      paragraph giving "oe Completion …" oe artifact). Contract Price already fixed
      via Php-preference asMoney (44/100) + final anchor.
    - S-curve exclusion already committed (document-parser.js:53 !isSCurveCandidate)
      so S-curve numbers no longer bleed into normal budget/title.
    - Plan updated to note location is project site (nullable CSPC text, consultant
      geotag future), Step 1 location fix already committed (project address only).

  2026-09-04 — User test results 1-5 + next scope (this commit, normal-first, no S-curve yet).
    - Q: "is per-file voting including cross checking of multiple informations such as
      project title and contractor?" Yes — per-file voting cross-checks each field
      separately (title, contractor, budget, budgetSource, category etc.) then flags
      mismatch per field. Plan already has Step 1d voting >50% + red banner picker.
    - Test 1 original scanned pdf: all 3 (title, contract price, contractor) now accurate.
    - Test 2 pdf recreation (contract_test_sample.pdf cropped top, ReportLab non-scanned,
      instant, no OCR): also accurate.
    - Test 3 upload both at same time: also accurate (cross-file check works, single blob
      merged previously now per-file voted).
    - Test 4 pdf recreation + intentionally added 2nd contract pdf from different project:
      it worked; with only 2 docs it prioritized first uploaded file (user OK with this,
      voting 1 vs 1 split → red banner let user pick, top vote shown).
    - Test 5 two similar pdfs + the different one (3 files, 2 identical vs 1 different):
      auto-fill prioritized the two similar datas (2/3 majority >50% wins — as designed).
    - Interpretation: per-file voting is indeed used for accuracy when same data repeats
      across docs, and it also detects mismatch/mis-upload (2-project case). Single-file
      case stays 1/1 =60 guessed, still accurate as above. Greedy title + cut still needed
      for multi-line titles (now accurate per test 2) — will be kept as polished fix.
    - User scope: no new implementation now; still wants other normal-doc improvements
      before S-curve (currently only title/price/contractor validated, other fields like
      location/category/budgetSource/description/dates not yet tested beyond test 4 file).
      So S-curve Steps 2-4 remain deferred; next normal-doc polish is value-side keyword
      confidence + ABC/price wording + S-curve exclusion already done.
    - Logged as requested before commit; no new implementation in this commit.

  2026-09-04 — CHS Savings scanned contract new issue + plan for next normal-only fixes (implementation started, not yet committed).
    - New file sampledocs/Completed Project/Contract - CHS Savings.pdf (EPSON Scan 2.2MB 7 pages,
      0 Tj, image-only, same OCR 10-page limit path) — bold underlined title
      "Construction of Four-Storey Academic Building (College of Health Sciences)-Savings"
      was already accurate via isTitleValueCandidate, but contract price empty and duration
      wrong (3 instead of 90). Evidence: Images 1 title bold underline already caught,
      but NINETY (90) calendar days read as 3 from "THREE PERCENT (3%)" discount line.
    - Q1 yes trust word even if digit missing: NINETY without (90) must still be 90.
      Q2 default to contract amount: the PESOS … (Php X) amount without a clear label
      should go to Contract Price (approved_budget), not ABC (ABC is ceiling, savings = ABC-price).
      Plus user: is it possible to use "PESOS" as keyword fallback if no clear label?
      Yes — same format in both Buhi and CHS: full words THIRTY-TWO MILLION … PESOS & 44/100
      (Php 32,588,531.44) and SIX MILLION … PESOS & 29/100 (Php 6,495,049.29).
    - Implementation started (this branch, not committed): document-parser-core.js
      parseDurationToDays now prefers (90) parentheses, then WORD_NUMS map
      ninety→90 etc. with % filter to ignore 3% unless calendar days present;
      extractDuration now scans "within … calendar days" fallback; extractBudget now
      falls back to PESOS … (Php X) and "the amount of …" clause via asMoney Php-prefer
      (>1000) so 6,495,049.29 wins over 29/100.
    - Kept S-curve deferred per user; no new PHP deps. Will verify with CHS scan next
      before any commit as requested "do not commit for now".

  2026-09-04 — Dynamic duration word→digit + AGENTS.md update (implementation, committed 95fdbac).
    - User: "then for now can you please implement the additional fix and adjustment, after that
      update the plan file, do not commit for now" — referring to CHS Savings fixes above.
    - User Q: hardcoded WORD_NUMS 1-90 + 100,150 etc. means numbers like 91,120,240 not detected;
      is there dynamic way? User confirmed: yes please do B — open with adjustments needing library,
      and do update the agent md file.
    - Implementation (committed AGENTS.md 95fdbac; core dynamic 1..3650 wordsToNumber vendored kept uncommitted per second do-not-commit):
      document-parser-core.js: added dynamic wordsToNumber(phrase) vendored logic
      (replaces hardcoded WORD_NUMS 1-90). Handles "ninety", "ninety-one", "one hundred",
      "one hundred twenty", "three hundred fifty" etc. hyphen/and, up to 3650 (days) to
      cover 90 vs 91, 120, 240 etc. Extends SCALES to million for budget PESOS words
      (SIX MILLION ...). Keeps core pure/node --test, no extra public/js/vendor
      bundle yet. AGENTS.md new § Document Parser Libraries documents pdfjs-dist, tesseract.js,
      phpspreadsheet, and duration words-to-numbers v1.5.1 concept as vendored.
    - Verified npm: words-to-numbers v1.5.1 and number-parsing v0.3.2 exist on registry.
      Decision: vendored dynamic function inside document-parser-core.js keeps no-vite
      (public/js) and avoids extra vendor file until a full npm words-to-numbers is preferred.
      If preferred later, vendor public/js/vendor/words-to-numbers.js and keep core wrapper.
    - No commit per latest instruction; S-curve still deferred.

  2026-09-04 — Budget alphabetic numbers follow-up (implementation, committed as part of 474bcff plan log, code kept uncommitted per do-not-commit).
    - User Q: what about budget related alphabetic numbers (SIX MILLION ... PESOS & 29/100)?
    - Answer: today budget relies on Php numeric (Php 6,495,049.29) via asMoney Php-prefer
      and PESOS + (Php ...) fallback (e6dbe53). Alphabetic words were ignored.
    - User confirmed: 1) yes default to contract amount (but only if none of ABC keyword
      exist), 2) for now only use words if php numeric absent (OCR garbled Php).
    - Implementation (working tree, not committed): document-parser-core.js wordsToNumber
      extended to million (was 100/thousand only) for budget PESOS words (SIX MILLION ...). Keeps Php numeric as primary; words only when Php absent per user. No new npm,
      no vite, keeps no-commit. To revisit: confirm whether & 72/100 vs 44/100 fractional
      cents handling (29 vs 72) needs extra test fixture.

  2026-09-04 — Working confirmation + AGENTS.md committed (95fdbac) — additional fixes verified, ready to push (no merge to develop).
    - User tests: CHS Savings duration 3 → now 90 (NINETY word→90 even without (90) + within calendar days combined window + fuzzy NlNETY OK), contract price 5049.29 → 6,495,049.29 (PESOS & 29/100 + P alias + million wordsToNumber + NET TOTAL default), Buhi 350 still correct. Console tesseract wasm "Line cannot be recognized!!" explained as harmless table grid skip.
    - User: strict ALL-CAPS bold for PESOS contract price words (both files have SIX MILLION … PESOS ALL CAPS bold, no underline — title is bold+underlined) — confirmed yes required for word fallback.
    - User: n alias (n 6,495,049.29) box only when paired with PESOS & 29/100 on same 1-2 lines → yes.
    - Implementation: additional fixes (P/n PESOS 2-line window, word 6495049.29 + .29, million scale) verified via node (NINETY→90, PESOS P→6495049.29, NET TOTAL, Buhi 350/32,588,531.44) and 39/39 tests pass.
    - Plan updated, AGENTS.md 9-line Document Parser Libraries committed as 95fdbac (first of two commits for last adjustment per user). Core + word strict + P/n fixes remain uncommitted until final push as requested.

  2026-09-04 — CHS-Savings single-file test + Step 1e plan (plan only, committed, no code).
    - User tested ONLY CHS - Savings.pdf (different paper type, bundle of inspection/
      acceptance/accomplishment/time-elapsed for same CHS 4-storey project). Result:
      title short but close (missing "(College of Health Sciences)-Savings" tail),
      location/contractor/end-date correct, budget 5049.29 tail (want 6,495,049.29),
      duration 1 (want 90, label is "Original Contract Time"), start empty (label is
      "Date of Effectivity of Contract"). End label "Date of Completion" already works.
      4 contract files remain the reference set.
    - User Q1 (price splice): answered — correct one-line "Original/Revised Contract
      Amount" was NEVER read (labels missing), so fallback "any large amount" grabbed
      a different section's split number first. Fix is labels first + money glue second.
    - User Q2 (accuracy/voting): confirmed — today FIRST match per file wins, vote is
      ONE per FILE (single-file = passthrough, no vote). User wants intra-file repeats
      to vote too. Planned as Step 1e-4 (all candidates per file → frequency +
      labeled-prefer + section-weight + full-length tie-break), then existing cross-file
      vote unchanged.
    - User decisions logged: Revised wins over Original (Decision 11); bundle reads ALL
      pages with section-weighted voting (Decision 12); Best-guess + correction stays.
    - Action: plan file updated with Step 1e (NOT STARTED) and committed per user
      "update plan then commit, no implementation". No JS/PHP touched in this commit.

  2026-09-04 — Step 1e revision: pooled global vote (plan only, committed, no code).
    - User Q: will cross-file vote use all finds from all files, not winners-only?
      Yes — revised per user "get all finds in all files is better".
    - Change: VALUE track = ONE pooled pile across all files (frequency + weights);
      WARNING track = keep one winner per file for red mis-upload banner. Same
      candidate pool feeds both — accuracy from pile, accountability per file.
    - Decision 12 updated accordingly. No code touched.

  2026-09-04 — Step 1e IMPLEMENTED (code + tests, awaiting user browser re-test).
    - Files: document-parser-core.js (1e-1 labels: original/revised amount w/ revised-wins,
      effectivity/date-of-effectivity, original/contract time; 1e-2 glueMoneyFragments in
      asMoney; 1e-3 ALL-CAPS title continuation + hyphen-break glue; 1e-4/1e-5 pooled
      helpers getSectionWeight/collectBudget|Duration|Date/voteCandidatePool/
      parseProjectInfoPooled + exports), document-parser.js (VALUE track pooled override,
      WARNING track unchanged via getMismatchInfo), tests/js/document-parser-core.test.mjs
      (+5 Step 1e fixtures).
    - Tests: node --test core 24/24 (19 old kept + 5 new), all JS 44/44 (core 24 + scurve 20).
      No PHP touched (parser is JS-only); SCurveImportTest unaffected.
    - Note: existing "ignores ambient amounts" test still passes (fallback keeps Php/NET
      TOTAL/P-prefix + >100k guard). Next: user re-uploads real CHS - Savings.pdf in
      browser and reports title/price/duration/start per-field results before any S-curve work.

  2026-09-04 — Step 1f IMPLEMENTED (code + tests, NOT committed per user).
    - Trigger: user re-test (title still short-acceptable, price 92025.00 NEW wrong,
      dates/duration accurate) + Image 1 (acceptance "Contract: ... - Savings located
      at ..." label) + accomplishment breakdown table with true total at bottom +
      "system adding rows?" Q (answered: never sums values, only weights) + console
      vote-log request.
    - Files: document-parser-core.js (scanContractLabelTitle + cutAtLocatedAt both paths,
      Amount/Time guard, voteCandidatePoolDebug + _debug, table weights 0.4/halve/0.5,
      budget ctx ±2), document-parser.js (window._autofillVoteDebug + _parserDebug
      console.table inspector), tests/js/document-parser-core.test.mjs (+3 Step 1f
      fixtures incl. failing-then-passing 1x-summary-vs-3x-table test).
    - Tests: core 27/27, full JS 47/47 (core 27 + scurve 20). No PHP touched.
    - No commit per user instruction; S-curve still deferred. Next: user browser
      re-test with inspector output paste-back if price still off.

  2026-09-04 — SESSION HANDOFF (pausing here per user; all work committed).
    - Where we are: Step 1f code+tests done and COMMITTED this session (Contract: title
      label + located-at cut, vote inspector, table tightening). Browser re-test by user:
      TITLE NOW CORRECT (acceptance "Contract:" line works), dates/duration accurate,
      price still wrong: inspector shows 92025.00 (2 finds, score 2) beats 6495049.29
      (1 find, score 1). The two 92025s are full-weight LABELED finds — weights alone
      cannot fix; need source excerpts (Step 1g-1) then Choice B ask-user (Step 1g-2).
    - User decisions banked: Revised wins over Original (D11); try-ALL-pages pooled VALUE
      vote + per-file WARNING vote (D12); Best-guess + correction always; short title
      acceptable fallback; Choice B now, Choice A/both later.
    - For the next model/session: start at STEP 1g above (NOT STARTED). Read
      §7 session logs (last 3 entries), Decision 11/12, the two others(notes)/ files, and
      run `node --test tests/js/document-parser-core.test.mjs` (expect 27/27) before
      touching code. Test files that must stay green: the 4 contract PDFs + CHS bundle
      browser check. Branch: phase-5-parser-accuracy (do NOT merge without user OK).
    - Working tree after this commit: CLEAN.

  2026-09-04 — Step 1g scope FINALIZED (plan only, no code).
    - User: excerpts first (pages only if excerpts fail); Choice B for ANY voted box
      (automation untouched for clean files — note appears only on ambiguity); single-file
      Q answered (part of the problem, not all of it — labeled repeats need the rule anyway).
    - Codebase study done (core collectors :924-1023, vote/debug :1028-1099, wrapper
      :46-169/:389-465, Blade shell components/autofill-preview-modal.blade.php).
      Page numbers confirmed LOST at :273 merge — deferred by design.
    - Action: Step 1g section rewritten with final scope + file:line map; committing and
      pushing per user, no implementation yet. NEXT = build 1g-1 when user says go.

  2026-09-04 — Step 1g IMPLEMENTED (code + tests, NOT committed per user).
    - 1g-1 excerpts: candidates gain {pattern, excerpt} — budget labelRes named
      (revised/final-anchor/discount-anchor/amount-is/generic) + fallbacks named
      (paren-php/net-total/p-prefix) + ±2-line excerptOf; duration/date collectors gain
      {pattern: labeled/within-clause, excerpt}. Debug rows gain {patterns[], excerpts[]
      (≤2 distinct)}. Codebase fact confirmed during build: page numbers are LOST at
      extractTextFromPDF merge (document-parser.js:273) — deferred by design, excerpts
      alone identify the source.
    - 1g-2 Choice B: core pooledDisagree(rows) → {winner, runnerUp} or null (both labeled,
      winner < 2× runner-up; silent otherwise); pooled return gains _disagree per field;
      wrapper stashes window._autofillDisagree, passes to showAutofillPreview, renders
      amber pick-one note with two one-click buttons (window._pickDisagreeValue) under
      budget/duration/startDate/endDate rows; input stays editable, Apply never blocked.
      Applies to ANY pooled field; title/contractor/location unchanged (red banner covers).
    - Tests (+3 Step 1g fixtures, core 30/30): pattern+excerpt presence + summary-pattern
      check; 1x-summary-vs-3x-table → winner filled AND disagree flag carries both values;
      clear-winner → null flag + unlabeled runner-up → null. Full JS 50/50
      (core 30 + scurve 20). No PHP touched. No commit per user.
    - Next: user browser re-test on real CHS bundle — (a) paste budget table with new
      patterns/excerpts columns to confirm the two 92025s' source, (b) confirm the amber
      note appears under Contract Price with both values clickable.

  2026-09-04 — Step 1h DIAGNOSED + planned; committing + pushing all (no 1h code).
    - User pasted expanded excerpts: 92025 patterns ['revised','generic'], ONE distinct
      excerpt around 'Date of Effectivity of Contract December 9, 202…' → user's
      date-ghost call CONFIRMED; mechanism = Step 1e-2 comma glue on '9, 2025' + double
      pattern match → 2-vs-1. Companion: 5049.29 from OCR '6.495,049.29' (period
      thousands). True 6,495,049.29 sits alone in the accomplishment-table bottom row.
    - Logged as STEP 1h above (glue thousands-only + date-exclusion + period-thousands).
      User: log + commit + push everything, no new fix yet. NEXT = build 1h on go-ahead.

  2026-09-04 — Post-1i roadmap AGREED (plan only, no code).
    - User: AI fallback for normal docs too (sensitivity reviewed — public-bidding data,
      slice-only + unsure-only gates stay); learning loop HYBRID (silent collect, live
      after 2–3 confirmations or admin one-click, deletable, never from unresolved files);
      sequence 1h/1i → learning loop (tested on bundle) → AI last.
    - Logged as POST-1i ROADMAP above. Committing + pushing all per user.

  2026-09-04 — Step 1h IMPLEMENTED (code + tests, NOT committed per user).
    - 1h-1: glueMoneyFragments comma rule /,\\s+(\\d)/ → /,\\s+(\\d{3}(?!\\d))/ — 'P 6,
      495,049.29' still glues, 'December 9, 2025' untouched (year = 4 digits).
    - 1h-2: collectBudgetCandidates labeled loop skips date-like values without money
      markers (Php/₱/P/pesos/amount + asDate check). Ghost path closed at the source.
    - 1h-3: period-thousands strip /(\\d)\\.(\\d{3})(?=[\\d,])/ in asMoney (money-marked
      only) + same strip in extractBudget's own third fallback AND the collector
      fallback scan (three sites had the same blindness; verified via failing-then-
      passing test on the bare '(Php …)' shape).
    - Tests (+3 Step 1h fixtures, core 33/33): glue years-vs-thousands; dates-never-
      candidates + pooled 1x-summary-vs-date-ghosts → 6495049.29 winner AND disagree
      null (amber note correctly stays silent on a solved case); period-thousands labeled
      + bare shapes. Full JS 53/53 (core 33 + scurve 20). No PHP touched. No commit.
    - Next: user browser re-test on real CHS bundle — price should fill 6,495,049.29
      with NO amber note; if any figure still off, paste the inspector table again.

  2026-09-04 — Step 1i LOGGED FRESH (plan only, no code).
    - User confirmed: 1h tested; 1i plan from the pre-AI/learning-loop table-photo
      discussion was never written into this file. Logged now as fresh STEP 1i above
      (placeholder rule + same-line priority + TOTAL CONTRACT AMOUNT first-class;
      year-block/period-thousands already in 1h, not repeated). No code, no commit
      instruction — NEXT = build 1i on go-ahead.

  2026-09-04 — Step 1i IMPLEMENTED (code + tests, NOT committed per user).
    - 1i-1: shared isPlaceholderValue() (xxx/none/n-a/nil/dashes, punctuation-tolerant);
      findLabeledValue stops the whole LINE on placeholder same-line or next-line values
      (labeled lineLoop) — item 4 (Revised: xxx) can never slide into item 5's date, for
      EVERY field not just budget; collector tryStrs loop breaks on placeholder likewise.
    - 1i-2: same-line labeled hits keep full weight, slid (next/next+2) hits ×0.8 — item 3's
      same-line Php beats borrowed figures before frequency counts.
    - 1i-3: 'total-row' named pattern leads labelRes; only the row's FIRST figure becomes a
      candidate (asMoney already takes first); lines it claims skip the generic re-match
      (totalRowHit set — one bottom-row total votes once). 'Total Cost P 92,025'-style rows
      still work (generic path unchanged). asBudget() normalizer mirrors the 1h-2 date
      guard into extractBudget's single-match revised/final/generic lookups.
    - Tests (+3 Step 1i fixtures, core 36/36): xxx+date below → null single-match AND no
      revised pooled candidate; same-line 6.4M beats slid lookalike at equal counts;
      total-row line yields exactly first figure (…040.32 never a candidate) with total-row
      pattern present. Full JS 56/56 (core 36 + scurve 20). No PHP touched. No commit.
    - Next: user browser re-test on real CHS bundle — price should fill 6,495,049.29
      (item 3 same-line + table header + total-row corroborate; Revised xxx silent).

  2026-09-04 — OCR starvation root-caused + adjustable budget designed (plan only, no code).
    - User's order-matters challenge (test 7 twice contract-first = bundle fields missing;
      bundle-first = everything extracts) → verified shared 10-page cap (document-parser.js
      :226 shared, reset only :36 never :50); clarity hypothesis ruled out (same 2x canvas).
      "Scan luck" corrected to deterministic starvation. Fixes approved: O1 per-file budget
      + reset per run, O2 truncation note, O3 per-file inspector counts. Adjustable budget
      approved as designed: opt-in "read more pages" control, default 10/file, explicit time
      warning (~2s/page), 50-page hard cap stays; recall up, accuracy not guaranteed.
    - Logged as OCR BUDGET STARVATION section above. NEXT = build 1i follow-up + O1-O3 on go-ahead.

  2026-09-04 — Browser re-test: 4/4 files accurate (user) + 1i follow-up planned.
    - Test 1 Contract-CHS Savings (scanned): title/price/duration/contractor accurate.
      Pool: budget 6495049.29 wins via word-amount fallback (paren-php, excerpt proves
      'SIX MILLION…' path works on scans); duration 90 via within-clause (unlabeled,
      unopposed). Inspector Q answered: tables cover ONLY the 4 pooled fields
      (budget/duration/startDate/endDate) — title/contractor/location/category/source/ABC/
      description are first-match + mismatch-banner by design, so no tables exist for them.
    - Test 2 contract_test_sample_2 (crop): accurate preview BUT budget pool EMPTY
      (null, finds 0) — single-match extractBudget carried it. Coverage gap: crop wording
      sits where first-match sees it but collector patterns don't; pool silently sits out.
      Dates pool likewise empty (no date labels in crop). No harm today (fallback chain),
      but a vote that doesn't count is logged for the audit below.
    - Test 3 Buhi original (scanned): all accurate. Pool at its best — truth 6 finds /
      score 4+ across 5 patterns (final-anchor/discount-anchor/amount-is/generic/paren-php)
      beats 33.5M original bid + 1M discount figure; duration 350 clean.
    - Test 4 contract_test_sample (crop of test 3): same, truth 6 finds / 4.13.
    - User next: multi-file runs — (a) original + recreation (expect agreement, no banners),
      (b) +1 intentionally different file (expect red banner with both values, majority fill).

  1i FOLLOW-UP (approved by user 2026-09-04, no code yet).
    - F1. Labeled dot-strip extension: thousand-dot strip applies to LABELED budget values
      even without a Php marker (matched label = money context). Narrow pattern only
      (dot + exactly-3-digits + digit/comma behind) so version numbers stay untouched.
      Expected: item-3-style lines read full; tail-vs-truth ties disappear → amber note
      silent on solved files.
    - F2. Collector coverage audit: find why test-2-style crops yield zero budget/date
      candidates while first-match succeeds; close the pattern gap so the pool
      participates (per-field fallback: when pool is empty AND single-match hit exists,
      optionally surface a low-confidence note — TBD in build).
    - Tests: (i) unmarked '6.495,049.29' labeled line reads full; (ii) crop fixture yields
      at-least-1 budget candidate; (iii) full suite green + 5-file browser set unchanged.

  OCR BUDGET STARVATION — root-caused 2026-09-04 (user re-tests; findings + fixes APPROVED, no code yet).    Finding (user's order-matters challenge → verified in code, document-parser.js):
      the photo-reader cap (OCR_PAGE_LIMIT = 10, :226) is SHARED across all files
      first-come-first-served in upload order, reset ONLY on popup close
      (cancelDocumentAutofill :36), never at preview start (:50). Rendering quality is
      order-independent (same 2x canvas always) — user's "clearer first image" hypothesis
      ruled out. Replay: test 7 contract-first (7 scanned pages eat ~7, recreation needs
      none, bundle gets ~3 then cut) → bundle dates/location/price missing TWICE the same
      way; bundle-first → bundle gets full 10 → everything extracts. The forgotten-debug
      re-run was most starved (counter never reset between previews). Earlier "scan luck"
      verdict CORRECTED to deterministic starvation. Multi-file runs inherit it: any
      scanned file after the budget is spent reads partial-but-silent.
    Fixes (user: yes/yes/yes):
      - O1. Fair per-file budget, reset every run: each file gets its own 10 scanned pages;
        counter resets at preview start. Order stops mattering; re-running stops starving.
      - O2. Truncation note: file with more scanned pages than budget shows a small note
        ("first 10 scanned pages read") — partial never poses as confident again.
      - O3. Per-file contribution counts in the inspector ("bundle: 0 photo-pages read")
        — turns silent-vs-fair questions into one glance (queued earlier, re-affirmed).
    Adjustable budget (user proposal — logged as designed, not yet built):
      - Preview shows a small "read more pages" control (default 10/file) for unsatisfied
        runs, with an explicit time warning (each page ~2s: 20 pages ~40s, 50 ~2min).
        Yes: more pages = higher recall on big bundles (later pages actually get read).
        Limits: NOT accuracy-guaranteed (later pages may hold no new fields; OCR text stays
        rough), and the 50-page/PDF ceiling (extractTextFromPDF maxPages) stays as the hard
        cap. Opt-in per run, never the default — keeps routine uploads fast.
    - Tests: (i) 3-file bundle-second run extracts bundle dates/price (order swapped both
      ways, same result); (ii) back-to-back previews without closing popup read identically;
      (iii) over-budget file shows the truncation note; (iv) full suite green.

  1i FOLLOW-UP + OCR FIXES — IMPLEMENTED + tested (2026-09-04), NOT COMMITTED per user.
    - F1: labeled-loop thousand-dot strip regardless of Php marker (matched label = money
      context; narrow digit-dot-3digits-digit pattern — "Section 1.495" untouched).
    - F2: 'bare-php' fallback pattern (bare "Php X" with no parens/NET-TOTAL/P-prefix) —
      closes the test-2 crop gap where first-match saw the price but the pool sat out.
      Unlabeled + low weight; ambient marker-less figures still ignored (old guard holds).
    - O1: per-file OCR budget — _ocrPagesDone resets in extractTextFromPDF per file;
      ocrPageLimit() reads window._ocrPageLimit (1–50, default 10). Order independence +
      re-run starvation both fixed at the source.
    - O2: truncation note — extractTextFromPDF returns {truncated}; preview renders a gray
      note naming cut files ("first N scanned pages read") — partial never poses again.
    - O3: _fileCounts per field per file in pooled return + inspector log line
      ("bundle: 0" = silent, not outvoted).
    - Adjustable: "Read more pages & re-scan (≈2s per page)" button in the preview
      (window._rescanMorePages: +15 pages up to 50, closes preview, re-runs). Opt-in per
      run, default untouched, no Blade change (dynamic DOM like the banners).
    - Tests (+3 follow-up fixtures, core 39/39): labeled unmarked thousand-dots + narrow
      safety; bare-Php pool join + ambient guard; _fileCounts shape (a≥1, silent file 0).
      Full JS 59/59 (core 39 + scurve 20). O1/O2/button are DOM-timing paths — covered by
      browser re-test, not unit tests. No PHP touched. No commit.
    - Next: user browser re-test — (a) bundle-second 3-file run extracts bundle fields
      (order swapped, same result); (b) truncation note appears on over-budget files;
      (c) re-scan button bumps budget and re-reads with the time warning visible.

  2026-09-04 — Checks A/B/C PASS + NEXT BATCH planned (user approved, no code yet).
    - A (order swapped both ways): identical previews; duration piles across all 3 files
      (4 finds, patterns within-clause + labeled); dates/location fill bundle-second.
      Order independence + re-run stability CONFIRMED in production. Starvation closed.
    - B (back-to-back previews): identical. O1 verified by hand.
    - C (truncation + re-scan): gray note names cut files; button bumps 10→25→40 with the
      time warning visible. User wording verdicts (all accepted): note is PER FILE (say so
      explicitly); drop the word "budget"; report REAL counts ("read X of Y scanned pages
      in <file>") instead of the blind +15 stepping (user: "how 40 for 7+12 pages?");
      replace fixed stepping with a page-count SELECTOR (10/25/40/50 per file, default 10,
      warning beside it); 50-page ceiling stays the hard cap.
    - Tail verdict: bundle still reads 5049.29 while contract files read full — mismatch
      banner lists both (working as designed). Root cause of the REMAINDER: the dot-strip
      lives in the voting path only; the single-match path (mismatch banner + single-file
      preview) never got it. Fix = move the narrow strip into the shared money reader so
      both paths behave identically.
    - Mismatch trivia STILL firing (')-Savings' vs ') -Savings', '&' vs 'AND') — normalize
      hardening was queued, never built; folded into this batch.

  2026-09-04 — Stale-banner bug logged (plan only, no code, no implementation).
    - User: bundle-alone WITHOUT page refresh shows previous run's red "2/3 files" banner
      (values correct); after full refresh the banner is gone and the end state is fully
      correct (truth filled, pool truth-only, no amber, no banner). User re-trying to see
      if it recurs before any fix is built.
    - Logged as STALE-STATE BUG section above (verified roots: uncleared window.* state +
      dynamic nodes; persisting scan depth; pending-docs cleared OK). S-batch S1-S3 planned.
    - Committing plan only per user (commit, no push ordered).

  NEXT BATCH (approved, no code yet).
    - N1. Shared dot-strip: move the narrow thousand-dot strip into asMoney itself (both
      paths inherit it). Same narrow pattern (dot + exactly-3-digits + digit/comma behind);
      version text stays untouched. Expected: tail-vs-truth ties vanish from preview fill
      AND mismatch banner on solved files.
    - N2. Normalize hardening: '&'→'and', punctuation/spacing-blind compare in
      normalizeForVote (+ wrapper twin). Same-project pairs stop flagging; true project
      differences still flag. Red banner keeps per-file remove-button design (picked earlier).
    - N3. Truncation note reword + real counts: no "budget" word; per-file phrasing; carry
      {scannedTotal, scannedRead} per file from extractTextFromPDF to the preview
      ("read X of Y scanned pages in <file>").
    - N4. Page-count selector + re-scan: dropdown (10/25/40/50 per file, default 10) with
      ≈2s/page warning beside it; button re-runs at the chosen depth (replaces +15 stepping).
    - Tests: (i) shared strip: unmarked '6.495,049.29' via single-match reads full;
      (ii) '&' vs 'AND' + space/punct variants vote equal (no banner); truly different
      titles still mismatch; (iii) full suite green + 5-file + multi-file browser re-check.

  2026-09-04 — N-batch + S-batch IMPLEMENTED (code + tests, NOT committed per user).
    - N1: strip moved into asMoney (both paths) + single-decimal guard (found by the suite:
      the total-row's fused '049.29100.00' would otherwise lose a real decimal dot; guard
      counts /\.\d{2}\b/ groups and strips only when exactly one). Same guard mirrored to
      the labeled-loop F1 strip + both fallback pre-normalizations (4 strip sites, one rule).
    - N2: core normalizeForPool + wrapper normalizeForVote hardened identically (&→and,
      non-alphanumeric collapse); normalizeForPool exported for tests.
    - S0: run-files + banner-decision log lines (preview call + render); S1: banner/note nodes
      rebuilt per render (remove-then-create; hide-branch deleted as a stale-state class);
      S2: runId travels to showAutofillPreview, stale renders return early with a warning;
      S3: cancelDocumentAutofill resets window vote/disagree/debug/depth state + removes
      dynamic nodes (runs on modal open; in-session re-scan depth preserved).
    - N3: extractTextFromPDF returns {scannedTotal, scannedRead}; per-file scanReports flow
      to the preview; note reworded with real counts, no "budget" word.
    - N4: selector (10/25/40/50, default 10) + Re-scan button via window._rescanWithSelection
      (replaces +15 _rescanMorePages); warning beside the selector; no Blade change.
    - Tests (+2 N fixtures, core 41/41): unmarked single-match strip + narrow safety;
      '&'/spacing/punct equality + real-difference separation. Full JS 61/61
      (core 41 + scurve 20). Wrapper S/O paths are DOM-timing — browser re-test covers them.
      No PHP touched. No commit.
    - Next: user browser re-test — (a) bundle price fills full with no banner on
      same-project sets; (b) single-file re-runs never show a stale banner; (c) gray note
      shows real "read X of Y" counts + selector works with the warning visible.

  2026-09-04 — N-batch + S-batch VERIFIED in production (user browser runs + log lines).
    - Test 1 (3-file same project): NO mismatch banner (N2 works); duration piles across
      files with labeled + within-clause patterns; hardened norms visible ('2024 12 09').
      Per-file counts line renders.
    - Test B (single-file re-run): 'preview run 3: files=[CHS - Savings.pdf] mismatch=false'
      + 'preview render run 3: mismatch banner hidden' — S0/S1/S2 proven; stale-banner class
      closed. The earlier stale sighting does not recur.
    - Test C (real counts + re-scan at 25): gray note reads per-file truth — 'CHS: read 9 of
      12 (10 per file selected)' / 'Contract: 7 of 7'; post-rescan '12 of 12' / '7 of 7'.
      Deeper pile grew (budget 8→9, duration gained a labeled contract-file hit) while ALL
      winners held — weighting proven at depth. One stray misread surfaced, harmless
      (single find, nothing agrees). N3/N4 proven.
    - Re-scan semantics (user Q, answered + logged): every re-scan is a FULL fresh run at
      the new ceiling (pages 1–10 re-read + 11–25 new) — honest cost is up to ~2s × depth,
      not "+15s". Nothing cached between runs today.
    - FUTURE (parked, not approved for build): incremental re-scan cache — keep per-file OCR
      text keyed by file + depth, read only newly uncovered pages on bump. Faster re-scans,
      but needs cache + invalidation rules for an occasional button. Build only if the wait
      starts annoying the user.
    - STALE-STATE bug: CLOSED by the Test B evidence above.

  2026-09-04 — P1+P2 IMPLEMENTED (code + tests, NOT committed per user).    - P1: core splitMismatchGroups(variants) → {connected, outliers} + export (pure, tested);
      banner renders a grouped verdict ("X, Y look like one project / Looks different: Z
      [Remove & re-scan]") driven by the title variants, outlier file lists stashed by index
      (quote-safe) in window._mismatchOutliers; window._removeMismatchFiles(idx) ejects matching
      pending docs via removeDocument(docId) (document-manager.js:198), closes preview, re-runs.
      Silent on agreement (single variant → no verdict, no buttons).
    - P2: unconditional one-line console hint per preview pointing at window._autofillVoteDebug
      (tables recoverable without the debug flag and without re-uploading; the stash itself
      was already unconditional — only the printing was gated).
    - Learning-loop question (user): YES next after this batch proves itself — and YES, the
      "DB-driven" §11 flagged item is the same destination (parser_keywords table + admin CRUD);
      the loop adds the journey (silent collect from corrections → live after 2–3 confirmations
      or admin one-click → deletable; never from unresolved files). Sequence stands:
      bundle accuracy → learning loop (tested on the bundle) → AI fallback last.
    - Tests (+1 P1 fixture, core 42/42): 3-vs-1 grouping + empty/agreement shapes. Full JS 62/62
      (core 42 + scurve 20). Banner buttons + remove-and-rerun flow are DOM-timing — browser
      proof covers them. No PHP touched. No commit.
    - Next: user browser proof on the mixed 3+1 set — verdict line + Remove & re-scan button
      visible; clicking it ejects the outlier and recomputes from the remaining three.

  2026-09-04 — P1/P2 BROWSER-PROVEN by user (verdict + remove buttons working "as far as
  I can see"); reader accuracy work declared COMPLETE through P1/P2.
    - Learning loop CONTINUES in textfiles/document_parser_learning_loop_plan.md (L1 silent
      collection → L2 review list → L3 reader consults table; staff+admin approval,
      labels-only, hybrid trust; AI fallback last). This file keeps history only — new
      learning work logs THERE, not here. Plan split per user (file too long).

  STALE-STATE BUG — reported 2026-09-04, root causes VERIFIED in code, S-batch IMPLEMENTED (2026-09-04), NOT COMMITTED per user.
    Symptom (user, bundle-alone run WITHOUT page refresh): preview values correct
      (truth filled, pool holds truth only) BUT the red "mismatch 2/3 files" banner from
      the PREVIOUS 3-file run still shows. After full page refresh: banner gone, end state
      fully correct. User will re-try to see if it recurs.
    Verified (read-only): (a) cancelDocumentAutofill (document-parser.js:34) resets run id
      + scanning banner + closes preview, but NEVER clears window._autofillMismatch /
      _autofillDisagree / _autofillVoteDebug / _ocrPageLimit and NEVER removes the dynamic
      #autofillPreviewMismatch / #autofillPreviewTruncated nodes; (b) window._ocrPageLimit
      persists across runs/sessions until full refresh (user's "40-page budget" screenshot
      on a fresh preview proves it); (c) pending documents ARE cleared on modal open
      (_create_project_modal.js:193) — old files are NOT reprocessed, leftovers are state-only.
    S-BATCH as built:
      - S0. Run-files + banner-decision log lines (preview call site + render site).
      - S1. Fresh nodes per render (remove-then-create for banner + gray note; the
        hide-branch is deleted as a stale-state class) + cancelDocumentAutofill resets all
        window vote/disagree/debug/depth state and removes dynamic nodes.
      - S2. runId travels into showAutofillPreview; stale renders return early + warn.
      - S3. Depth default resets per modal session (cancel path); in-session re-scan bumps
        preserved (cancel is NOT called on re-scan re-runs).

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

  Decision 11 — Revised Contract Amount wins over Original (Step 1e)
    Context: CHS-Savings bundle has both "Original Contract Amount" and "Revised
      Contract Amount" (equal here, may differ when change orders exist). System
      today has only Contract Price + ABC, no revised field.
    Options: (A) Original always, (B) Revised if present else Original, (C) manual edit.
    Decision: (B) Prefer revised when found, else original (user 2026-09-04).
    Rationale: Completed projects with adjustments should auto-fill the current
      amount; user still reviews in preview before Apply (Best-guess + correction).
    Consequences: Step 1e-1 adds both labels with revised-override rule; no schema
      change (still fills approved_budget).

  Decision 12 — Bundle PDFs read ALL pages: pooled VALUE vote + per-file WARNING vote
    Context: CHS-Savings.pdf bundles inspection/acceptance/accomplishment/time-elapsed.
      User asked: try all pages — won't keywords + cross-check raise accuracy? Then
      revised: get ALL finds in ALL files pooled, not winners-only.
    Options: (A) contract-pages only, (B) all pages merged first-match, (C) all pages
      with per-file winners voting, (D) all finds pooled for VALUE + per-file winners
      for WARNING.
    Decision: (D) ONE pooled pile across all files for the preview value (frequency +
      labeled-prefer + contract-section weight 1.0 vs supporting 0.6 + full-length
      money tie-break; weight beats sheer count so a thick bundle can't cheat), plus
      ONE winner per FILE kept separately for the red mis-upload banner (user 2026-09-04).
    Rationale: Accuracy from the pile (3x + 2x = 5 pooled beats a wrong first-hit);
      accountability from per-file winners ("File 1 says X, File 2 says Y").
    Consequences: Step 1e-4/1e-5; single-file 1/1 passthrough → N/1 voted; multi-file
      winners-vote → pooled-vote for value, per-file winners retained for mismatch.

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

  - E1. EXCEL HEADER-LEARNING (user-proposed 2026-09-08 — the Excel analog of the normal-doc
    learning loop; revives parser_templates with a capture-first design). Status: PARKED,
    plan only. Shape: unknown-header capture (on "No S-curve table found" 422s, stash the
    header block) → review desk cloned from the keyword desk (same approve/reject/occurrences
    trust, staff+admin per L0a) → live maps consulted by findHeader as lowest-priority
    fallback, keyed per contractor where known (same-format evidence: BUHI, roof+hard-copy).
    Distinct from normal-doc parser_keywords (vocabulary) — this learns LAYOUTS. Feeds the
    R2a fuzzy layer instead of replacing it (fuzzy catches typos; maps cover novel layouts).

  - E2. AI FALLBACK FOR EXCEL (user-proposed 2026-09-08). Status: PARKED, plan only. Design
    (no renderer needed — key insight): serialize server-side via PhpSpreadsheet to COMPACT
    TEXT (header block + first ~40 data rows as CSV, never the whole workbook) and send text,
    not images; Gemini returns the same {months, meta} JSON shape; PHP validates (monotonic,
    force-last-100, contract sanity) exactly like the service. Triggers mirror normal-doc:
    422 no-table OR low-confidence import, opt-in banner, merge-for-review (never auto-apply),
    one call per file, Http::fake tests. Sensitivity: schedule numbers only (public-bidding
    data), same slice-minimal precedent as the normal-doc slice.

  - QUEUE (user-confirmed 2026-09-08): Excel end-to-end first (2a/R2a/2b/fallback/UX done;
    2c parked above); then ONE non-OCR PDF batch (monthly-path parity 1–5 + weekly polish +
    PDF header-synonym mirroring; Tier-2 OCR stays separately queued as engine work); E1/E2
    unpark only on explicit order. Blinding stays ON throughout (demo proof).

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
    NOTE (user Q 2026-09-04 — two DB-driven items, do not confuse):
    (a) THIS parser_keywords item = NORMAL-doc vocabulary = the learning loop's
    destination (loop adds the journey: silent collect → live after repeats/admin
    approval; see POST-1i ROADMAP). (b) The OTHER item, parser_templates
    (per-contractor S-curve column_map JSON + _template_mapper_modal, Decision 8),
    belongs to the S-CURVE Opt-in matcher tier — untouched, deferred with Steps 2-4.

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
  13. PLAIN LANGUAGE SUMMARY — Non-Technical Detailed Explanation
===============================================================================

  Goal: Make creating a new project almost automatic. The system reads your
  uploaded PDFs or Excel files, fills the form for you, and you only fix what
  it got wrong. Keep using the free AI tier and send as little data as possible.

  What works today:
    - Excel S-curve (Updated-S-Curve-3-Storey-Bldg.xlsx) is accurate. It uses
      PhpSpreadsheet to read the 24 small boxes (15,15,5 days) that make up the
      12 months (I:J = Month 1, K:L = Month 2 … AE:AF = Month 12). For each
      activity row it adds the two boxes for a month (e.g., 7,469 + 7,469). If
      the sum is greater than zero, that activity is listed under that month.
      Example: Clearing shows money only in box I → only Month 1; Field Office
      shows 7,469 in every box → all 12 months. This was re-checked and is
      correct — the earlier "59 projects" comment was confusion with the list.

    - PDF S-curve has 2 layouts that work: monthly columns (Month 1..12) and
      weekly (WEEK 1..12 under DECEMBER/JANUARY/FEBRUARY, A3 2-page overflow).
      Your CONSCHEDSAVINGS.pdf weekly case works using the exact position (x,y)
      of each word on the page. Scanned PDFs fail — your new scurvepdfsample2.pdf
      (389KB Letter, photo from phone, Skia export) has zero selectable words;
      the same happens for ## (Excel showed "###" when the column was too narrow
      to print the money) and the other copy's weighted % (1.20% per month)
      — both are ignored today because the code only looks for plain numbers.

    - Normal contract PDFs (Title, ABC/Contract Price, Budget Source, Category,
      Contractor, dates) are found by labels. It looks for "Project Title:" then
      takes what is after it, same line or the next two lines (tables often put
      the value below). ABC as a lone "ABC:" , Budget Source, and Category have
      no extractor yet. Your 15 title phrases (Provision of, Rehabilitation…,
      Design and Build…) and 6 contractor suffixes (Construction and Supply…)
      are value-side hints — today the system only checks the label, not the
      value word.

  How we will make it more accurate — 4 tiers, least data sent:
    - Tier 1 — Fast local (no internet, no cost). Widen the dictionaries:
      add lone ABC, Source of Fund / Funding Source, Category / Project Type,
      extra NTP Date, and your 15 title + 6 contractor value lists as a fallback
      when no label exists (hardcoded now, learned from the database later).
      If the budget source or contractor name is not in the 9 BudgetSource or
      4 Category entries or the contractor table, we show: "Budget source 'GAD
      Trust Fund' not found — ask admin via (+) (admin only per
      BudgetSourceController.php:33)". Same for contractor — keep the text, warn.

    - Tier 2 — Local OCR, automatic, with tilt fix. When pdf.js finds fewer
      than 10 words (like scurvepdfsample2.pdf 0 words), the browser automatically
      turns the page into a picture and reads it with tesseract.js (already used
      for contracts: document-parser.js:142). Now with language eng+osd — it
      detects and straightens a slightly tilted phone photo, adds about 2 seconds
      on slow campus Wi-Fi (you approved auto, not a button), cached after first
      use, no document leaves the server. It treats ## as "activity present in
      this month" and 1.20% as a number.

    - Tier 3 — AI, very last, tiny slice, Opt-in. Only if the scan still looks
      unsure (fewer than 2 months, or the summary vs activity math differs by
      more than 1%, or table miss), we show a quiet banner: "Parsing looks
      uncertain — [Try AI assist (free)?] · [Show advanced mapping]" (you chose
      Opt-in, not auto-popup). If clicked, only the 1-2 important pages as a
      small 150 DPI picture (not the whole 5 MB contract) go to Gemini 2.0 Flash
      (config/services.php:45 existing key) for a strict JSON answer, then we
      clamp it to 0-100% and warn if the contractor's summary 6.5% vs our activity
      sum 6.74% mismatch — Validated (you chose validation over blind trust).
      Handles label-less CONSCHEDSAVINGS.pdf via pattern findCumulativeRow:379.

    - Tier 4 — Fully offline fallback (poppler) — only if the client later
      rejects any cloud. smalot/pdfparser is rejected for S-curves (loses
      positions). Python pdfplumber/PyMuPDF is flagged for future only (needs a
      Python server, not your current Windows XAMPP PHP on shared hosting).

  Libraries: Keep PhpSpreadsheet + pdfjs-dist@4.4.168, reuse tesseract.js
  eng+osd for scans, reuse google/apiclient Http for Gemini — no new paid
  library, no extra PHP install in v1.

  Steps when approved: Step 0 baseline tests on sampledocs → Step 1 widened
  labels + hardcoded value lists (+ Option A warnings) → Step 2 summary-row
  Validated (use summary when it exists, warn >1% else use activity; + ##/% +
  single-col fix) → Step 3a OCR auto + Step 3b AI very last slice → Step 4
  confidence gate (Auto OCR vs Opt-in AI) → Step 5 tests/manual QA + Step 6 logs.
  Branch phase-5-parser-accuracy from phase-3-pdf-scurve-import (Rule 12).

  Flagged later: per-month activity distribution test fix, parser_keywords
  table after enough projects, parser_templates column-map per contractor
  (Opt-in advanced mapper), and Python pdfplumber 3-rule micro-service only
  if JS + OCR + Gemini still miss a layout.

===============================================================================
  END OF PLAN
===============================================================================

# AI FALLBACK FOR DOCUMENT PARSING (TIER 3, VERY LAST)
# Parent refs: textfiles/document_parser_accuracy_improvement_plan.md (Steps 3b/4, S-curve slice)
#              textfiles/document_parser_learning_loop_plan.md (sequence: rules → loop → AI last)
# Date: September 8, 2026 — Status: PLANNED (restudied 2026-09-08, no code yet)
# Branch on build: phase-<n>-ai-fallback (FROM merge15-baseline; Rule 12, no merge without OK).

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name / Client / Framework
  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).

Why this file exists
  The accuracy plan's Tier-3 sketch (Steps 3b/4) and the learning-loop sequence both point
  at AI fallback as the LAST resort, but neither holds the implementation-ready detail.
  This file is the single home for it. S-curve AI (parseSCurveSlice) is parked inside it
  until normal-doc AI is proven. The blinding demo, the L1-L3 loop, and all rule work are
  untouched by this plan (triggers are scoped so blinded misses never call AI).

Position in the pipeline (user-approved, ascending cost/risk)
  Tier 1 local rules (free/offline) → OCR paths (free/in-browser) → L1-L3 learning loop →
  AI fallback (quota/latency/data-leaves) — THIS FILE → S-curve Steps 2-4 + Tier-2/3.

Frontend convention (IMPORTANT)
  App UI JS/CSS lives in `public/` linked via asset('...') — NO @vite for new features.

Sensitivity basis (user-approved)
  Contract/supporting papers are public-bidding records (contractor names, business
  addresses, working emails exist to be visible). Slice-only (1-2 confusing pages as a small
  image, never the whole file) + unsure-only (local reader admits defeat first) gates stay.

===============================================================================
  2. DECISIONS BANKED (user, plain words)
===============================================================================

  Decision A1 — Normal-doc first (2026-09-08).
    S-curve AI waits until normal-doc AI is proven on test4. No parseSCurveSlice this round.

  Decision A2 — Blinded ABC never triggers AI (2026-09-08).
    While blinding is ON, ABC-empty on clean files is artificial. Triggers are defined so
    clean files (high confidence otherwise) never offer AI; test4-class files (low
    confidence) do. No blinding-specific code — the confidence math excludes it naturally.

  Decision A3 — Learn everything (2026-09-08).
    Kept AI values ARE harvested as proposals (user overrode the learn-only-fixes
    recommendation). Consequence accepted: AI-influenced phrasing enters the desk.
    Safety rail (built in, no extra decision): every proposal carries origin `source`
    (correction vs AI-assisted) shown as a desk badge — same column the parked passive
    plan specifies, built once and reused twice. Natural guard: hallucinated values absent
    from the text find no anchor, so the miner returns nothing.

  Decision A4 — Auto weakest pages (2026-09-08).
    The slice is the first 2 scanned/low-yield pages automatically (scanReports know which).
    No user page-picker this round.

===============================================================================
  3. STATUS SUMMARY
===============================================================================

  Server Gemini precedent (reusable as-is)        ✅ EXISTS (AIRecommendationService:787-905)
  Tier-3 design (accuracy plan Steps 3b/4)         ✅ PLANNED (this file makes it build-ready)
  Normal-doc AI (service/endpoint/banner/tests)    🔄 IN PROGRESS (AI-1 done, AI-2 next)
  S-curve AI (parseSCurveSlice)                    🔜 PARKED (after normal-doc proof)
  Browser proof file                               ✅ READY (test_page_6.pdf class + test4 scans)

===============================================================================
  4. WHERE THE HOOKS GO (restudy 2026-09-08, read-only)
===============================================================================

  Server template (copy, don't reinvent)
  --------------------------------------
    - Config: config/services.php:44-47 (gemini key = env AI_API_KEY, model = env AI_MODEL).
    - HTTP shape: Http::timeout(30)->post(generateContent?key=, {contents:[{parts:[{text}]]}})
      (AIRecommendationService:787-819); fail-closed to '' on empty key / non-2xx / exception.
    - Fence-strip + validate idiom: parseResponse:835-860, validateRecommendation:879-905.
    - First Http::fake() use in the suite (no precedent — standard Laravel fake).

  Endpoints (mirror SCurveImportController dual-input + response shapes)
  ----------------------------------------------------------------------
    - New app/Services/AI/GeminiDocumentParserService.php: parseProjectSlice(array $images).
      Strict-JSON prompt (11 fields, dates YYYY-MM-DD, null when absent) + PHP-side validation
      (date normalizer + money sanity + field allowlist). Graceful decline without a key.
    - New DocumentParserController::parseWithGemini() (file | document_id via
      Document::full_path, same resolveSource shape as SCurveImportController:60-94).
    - Routes POST /{role}/document/parse-gemini in staff (web.php:200 area) + admin
      (web.php:591 area) groups only; names staff/admin.projects.document.parse-gemini.
    - .env.example gains AI_API_KEY= / AI_MODEL= placeholders (currently absent).

  Client (no Blade change — dynamic S1 banner pattern)
  ----------------------------------------------------
    - Banner: opt-in amber "Parsing looks uncertain — [Try AI assist?]" in
      showAutofillPreview() (document-parser.js:1170), beside mismatch/truncation nodes
      (1351-1394/1281-1345), shown on low parsed._confidence (core:1716, 11-field) /
      unresolved pooled disagree (:306) / near-empty fill. One AI call per file max.
    - Slice: weakest-2-pages canvas render at 150 DPI via toDataURL; role-base + CSRF fetch
      pattern from harvestCorrections (:1478-1510); contractors excluded (:path check).
    - Merge: AI values into preview inputs with small "AI-assisted" marker (disagree-note row
      slot :1215-1232); stamped in window._autofillAiAssisted for harvest sourcing; Apply stays
      manual — never auto-applied.

  Harvest sourcing (Decision A3 rail)
  -----------------------------------
    - Migration: `source` column on parser_keyword_proposals ('correction' default,
      'AI-assisted' for AI-touched keeps) + desk badge column (shared with parked P1).
    - Harvest loop gains source tagging; miner + caps + trust rules unchanged (≥3 auto-live,
      one-click approve/reject, staff+admin per L0a).

===============================================================================
  5. IMPLEMENTATION STEPS (in order; each proved before the next)
===============================================================================

  AI-1 Server service + unit tests (no UI).
    - GeminiDocumentParserService with fence-strip + field validation; Http::fake tests
      (success-shape, fence-wrapped JSON, invalid-shape dropped, empty-key decline).

  AI-2 Endpoint + routes + contract tests.
    - parseWithGemini (file | document_id, 201/422 shapes mirroring SCurveImportController);
      contractor blocked; .env.example keys. Feature tests with Http::fake + Storage::fake.

  AI-3 Banner + slice + merge (client only).
    - Amber opt-in on the three trigger states (A2: clean blinded files stay silent);
      weakest-2-pages slice; AI marker + AiAssisted stamping; JS trigger fixtures.

  AI-4 Harvest sourcing (migration + badge + loop tagging).
    - `source` column, desk badge, harvest tags AI-kept rows; existing rows default
      'correction'. PHP + JS suites green (full-suite pre-existing failures re-verified
      against the clean tree per standing practice).

  AI-5 Browser proof on test4 (user).
    - Dark-at-every-angle file: banner appears, slice sent once, preview merges for review,
      kept/corrected values harvest with AI source. THEN the step is closed.

  AI-6 S-curve AI (PARKED — separate go-ahead).
    - parseSCurveSlice + parse-gemini route + months validation (monotonic, last = 100,
      Validated summary-vs-activity warning). Not started until AI-5 passes.

===============================================================================
  6. SESSION LOGS
===============================================================================

  2026-09-08 — File created (plan only, no code).
    - Ordered by user: dedicated AI file; accuracy + learning-loop plans to reference it.
    - Restudied via two explore agents (preview/apply/harvest seams; server/route/test seams).
      Key catch banked as Decision A3 context: kept AI values would otherwise harvest as
      "corrections" with no origin flag — answered by source tagging, not by skipping.

  2026-09-08 — AI-1 BUILT on merge15-baseline (code, NOT committed; no UI yet).
    - Service app/Services/AI/GeminiDocumentParserService.php: parseProjectSlice(images) with
      strict-JSON prompt, 3-image cap, fence-strip + per-field validation (11-key shape, dates
      to YYYY-MM-DD, money to DDDDDD.CC, duration 1-3650, unknown keys dropped, all-null → null,
      fail-closed on empty key / non-2xx / exception — same shape as AIRecommendationService).
    - Caught pre-green: mixed-separator branch mangled normal US figures ("4,323,196.77" →
      "4,323,19677"); replaced with last-separator-wins decimal (both US + OCR period-thousands
      resolve; verified by the failing-then-passing success test).
    - Tests tests/Feature/Services/AI/GeminiDocumentParserServiceTest.php: 7/7 green
      (success-shape + normalization, fences, malformed/list/500 decline, empty-key sends
      nothing, all-null + empty-images decline, 3-image cap asserted on the wire, invalid
      values → null). First Http::fake() use in the suite. No commit without OK.
    - Awaiting build go-ahead (phase branch per Rule 12).

===============================================================================
  7. OPEN QUESTIONS / STILL TO RESOLVE
===============================================================================

  - Confidence threshold value for the banner (e.g. _confidence < 40?): finalize at AI-3 with
    live numbers from test4 vs clean files — not guessed now.
  - stored-document slice path (document_id render server-side vs instruct re-upload):
    decided at AI-3; create-wizard File path builds first either way.
  - Determinism fix (DevTools-OCR variance issue) ordering vs AI batch: user to decide —
    preset-engine cure removes unsolved pages before they ever reach AI triggers.

===============================================================================
  8. CONVERSATION CONTINUATION GUIDE
===============================================================================

  For the next session:
    1. Build order AI-1 → AI-5 (AI-6 parked). Blinding stays ON; triggers exclude blinded ABC.
    2. Run JS suite + ParserKeywordProposalTest before/after each step; Http::fake for services.
    3. Browser proof on test4 closes AI-5. No merge without explicit user confirmation (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================

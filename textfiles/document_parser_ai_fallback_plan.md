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
  Normal-doc AI (service/endpoint/banner/tests)    🔄 IN PROGRESS (AI-1 + AI-2 done, AI-3 next)
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

  E2 PARITY 2026-09-09 (Excel AI, live bug → fix; AI-6 stays parked — this is the
  E2 Excel path, not the PDF-slice AI-6).
    - Finding (user, real third-family file): AI returned the author's summary (45%)
      while rules read activity math (50%) — prompt asked for one series, so the AI
      parroted the summary silently. Fix: two-series prompt + reference+warn + flagged
      summary-only fallback (mirrors 2b; see accuracy plan E2 PARITY entry). +4 service
      tests green.
    - NEXT (user-approved 2026-09-09): A point-and-teach desk (reviewer assigns columns
      for map-less rows; lettered-list picking proposed) → B learn-from-AI maps (E2
      success on rules-failed files validates back to columns; proposed-only, one-click
      approval required — never auto-live, hallucinations must not self-trust).
    - STRICT HOMEWORK + PREFER-MATH BUILT 2026-09-09 (code, see accuracy plan E2 entry
      for the cause chain): prompt homework + server recompute-decline + mirror/unverified
      notes + endpoint prefer-math swap. +4 service / +2 endpoint tests green.
    - ADJUSTED PLAN BUILT 2026-09-09 (code, NOT committed per user): schema-enforced
      homework + universal verdict + debug breadcrumb + on-demand cross-check link
      (choice a: quiet confirmation on agreement). See accuracy plan E2 ADJUSTED PLAN
      BUILT entry for the full record.
    - LIVE EVIDENCE + REVISED QUEUE 2026-09-10 (user: webapp flash computed correctly from
      the xlsx and flagged row 14; live log shows full 10/10/10/true homework with mirror
      note fired; pure-AI path confirmed to do no independent recomputation). Decisions:
      in-modal link + quiet toast (a) CONFIRMED; model bump deprioritized. Build queue
      (NOT STARTED): slice reform → presence-audit gate (mirror+absent = decline) →
      dedupe fix → in-modal link → tests/suites. See accuracy plan LIVE EVIDENCE entry.
    - REVISED QUEUE BUILT 2026-09-10 (code, NOT committed per user — full record in the
      accuracy plan E2 entry): tagged slice + repaired prompt + presence gate + dedupe +
      in-modal link. Suites: AI service 22/22, import 32/32; full 1083 passed / same 7
      pre-existing; JS 90/92 (2 intended blinding fails).
    - NATIVE + TEMP0 BUILT 2026-09-10 (code, NOT committed per user): inline workbook part
      (OLE sniff, 15MB ceiling + CSV fallback note) + temperature 0.0 on the wire. Tests:
      +2 service (file part, text-only), +1 controller (MIME/ceiling/ghost). Full 1086
      passed / same 7 pre-existing; JS 90/92. See accuracy plan.
    - LIVE ROUNDUP 2026-09-10 (user browser; parser model gemini-3.7-flash): true-curve
      first try, unknown-format offer, tomorrow-message, cross-check agree, map import —
      all proven live. Rate verdict: upload innocent, 3.8-flash RPD-20 + saturation guilty.
      Unproven: diverge warning, fallback file, retry-heal sighting, fail-fast line,
      duplicate capture, contractor map, button look. OPEN GAP: normal-doc assist shows
      hardcoded generics (never the mapped transport messages) — next build. Full record
      in accuracy plan.
    - NORMAL-DOC MESSAGE PARITY BUILT 2026-09-10 (code, NOT committed per user).
      Root cause (user report: normal-doc assist on latest says only "could not read"
      while 3.7 works): postAiSlices DISCARDED data.message — mapped transport voices
      died there, so all four failure spots could only print generics. Fix: stash the
      server message per call (aiFailureMessage helper) + thread through both banner and
      empty-warning flows (inline error + toasts), generic text kept as fallback.
      node --check clean; JS 90/92 (core untouched — wrapper-only change, browser proof
      is the user's step: storm-time assist should now speak quota/busy/dead-model).
      review knows the provenance. Lite pool verified breathing (user probe: 200 STOP).
      Build next (NOT committed per user order).
    - FALLBACK BUILT 2026-09-10 (code, NOT committed per user).
      GeminiTransportException (carries status + quotaId; extends RuntimeException so all
      existing catches/messages/tests hold) thrown by postGemini instead of silent '';
      callGeminiWithImages catches eligible failures for ONE Lite attempt (backup model =
      admin-chosen main model, never hardcoded; same key, same project) then flows through
      unchanged validation; Excel sender untouched (no fallback branch by design).
      Provenance: service lastFallbackModel flag → controller fallbackModel field →
      client backup-model suffix on the merge notice (+ per-call stash reset).
      Tests: +images-fallback-to-Lite (sequence 429/429/200, Lite URL + flag asserted),
      +excel-never-falls-back, +no-fallback-on-auth (401 single-shot), +endpoint
      provenance flag. Suites below.
    - FALLBACK PROVEN LIVE 2026-09-10 (user browser; committed + pushed this session).
      Log chain: 503 → retry 503 → "fallback to Lite" info → extraction succeeded; merge
      notice showed "Answered by backup model — verify carefully." verbatim (user-confirmed).
      Parser model at test time was flash-latest BY USER CHOICE (deliberate overload/quota
      trigger to exercise the fallback — not the standing 3.7 pin for daily use, which
      remains the recommendation).
    - MODEL VERDICT CLOSED 2026-09-10 (user live test): institute second key + 
      gemini-flash-latest live (pinned 2.5 retired/404s; drift caveat accepted). Flash
      returned the TRUE curve with four honest warnings — Lite copied under identical
      conditions. Full record in document_parser_key_model_split_plan.md + accuracy plan.
    - DECISIONS 2026-09-10 (logged only, NO implementation): whole-workbook native upload
      APPROVED (public-transparency justification; guards stay); temperature 0 EXPLAINED,
      decision rides with build go-ahead (deterministic ≠ correct; Excel path only).
      NEXT BUILD QUEUE (NOT STARTED): native bytes + companion CSV → temp 0 on wire →
      validation untouched → tests/suites → commit/push on word. See accuracy plan.
    - CONFIRMED GAP + ADJUSTED PLAN 2026-09-09 (user re-test with stale code ruled out:
      wiped maps + fresh serve → silent 45; model sent months-only, all checks abstained —
      conditional verification fails open). Next, NO implementation yet: schema-enforced
      homework + universal verdict (verified/compared/unverified, never silent) + debug
      breadcrumb + on-demand cross-check link (user choice a: quiet confirmation on
      agreement). Fixes the impossible approve-then-AI test flow. See accuracy plan E2
      ADJUSTED PLAN for the full five steps. A/B stay queued behind.

  B. LEARN-FROM-AI MAPS (BUILT 2026-09-10, code NOT committed per user)
    Idea (user-approved direction): an AI success on a rules-failed file should leave a
    reusable layout behind, so the next identical file parses without spending quota.
    - B-1 prompt/schema/layout: headerRow + monthColumns[] + desc/contract/weight/code
      letters asked (optional keys), schema extended, debug log counts, result carries
      layout{activityBased, mirrored} (controller strips before responding).
    - B-2 deriveMapFromAiLayout: R#→sheet key via kept-ordinal re-walk (same 40-row
      recipe as serialize); letters→indices; header row = reported line if it carries
      the desc label else relaxed-detected row; full buildTaughtMap gates + numbers-proof
      on real bytes; single-header v1 only; best-effort null throughout.
    - B-3 store: model storeAiDerived (backfill map-less match + drop-to-proposed, else
      create proposed ai-derived; occurrences never touched); collect() auto-promote
      skips ai-derived rows (absolute never-auto-live); SOURCES += ai-derived + desk
      badge; controller LearnMap step after unswapped success (contractor scope mirrored);
      wizard assist sends contractorSelect.
    - Caught pre-green: (1) wiring passed $result['layout'] into a helper expecting
      $result (layout read as null — silent skip found by the endpoint test); (2) docblock
      opener eaten again (repaired, php -l clean).
    - Tests: +layout passthrough, +adopted-unlearnable, +derivation valid, +refuses
      untrusted (4 shapes), +backfill-or-create, +never-auto-live (guard hit directly),
      +endpoint learns row, +consults-after-approval. Header 34/34; full 1116 passed /
      same 7 pre-existing; JS 90/92. Browser proof (novel file → AI → proposed row →
      approve → parse without AI) is the user's step. NO commit per user.

    B-0 Banked principles (recommendations — user can override):
      - Proposed-only, NEVER auto-live (not even at ≥3 repeats): a hallucinated layout
        must not self-trust into baselines. Approval stays the sole live flip.
      - Skip learning when the answer is untrusted: mirrored months, adopted-from-summary
        fallback, or preferMapMath already fired (a map exists — nothing to learn).
      - Excel-only (AI path holds no PDF renderer); zero extra AI cost (reuses the
        already-paid answer); best-effort inline (never breaks the 200 response).

    B-1 Prompt extension (GeminiDocumentParserService::buildSCurvePrompt + schema):
      ask for headerRow (R-number as shown), monthColumns ({m: [letters]}),
      descCol/contractCol (+weightCol/codeCol optional) letters. Schema keys OPTIONAL
      (weight-only/summary-less files stay legal); debug log gains the new key counts.

    B-2 Derivation helper (new SCurveImportService::deriveMapFromAiLayout, beside
      deriveAutoColumnMap): map AI R-number → sheet key by re-walking kept ordinals
      (serializeForAi keeps R = kept-ordinal, NOT sheet rows); letters → 0-based;
      header row = AI-reported line if its cells carry the desc label else the
      relaxed-detected row; sig built from that row; single-header-row B v1 only
      (two-row files honestly yield nothing). Then the FULL buildTaughtMap gate set
      (≥2 months, int desc, disjoint spans, desc-outside-spans, ascending months,
      sig≥2 with desc cell) PLUS mapSpansHoldNumbers against the REAL bytes.

    B-3 Store (collect upsert reuse): hash = sha256 of serializeForAi($path, 15)
      (same 15-row recipe as capture, NOT the 40-row AI slice); contractor scope from
      the request (explicit contractor_id else document→project — AI routes carry no
      project_id); matching null-map row gets backfilled, else create proposed with
      source ai-derived. SOURCES += ai-derived; desk origin badge branch; approve/
      reject/teach work unchanged (teach can edit ai-derived maps too).

    B-4 Tests: faked AI with columns → proposed map with valid shape; mirror →
      nothing stored; adopted-summary → nothing stored; preferMap-fired → nothing
      stored; invalid letters → nothing stored; repeat stays proposed (never auto-live);
      endpoint integration (rules-failed file + faked columns → 200 + map row);
      full + JS suites with clean-tree check.

    B-5 Proof (user browser): novel-layout file (rules AND relaxed both miss) → AI
      assist succeeds → proposed ai-derived row appears → approve → re-import parses
      with the learned-layout note and NO AI call.

    Open for user: (1) never-auto-live absolute? (2) single-header B v1, two-row later?
      (3) go to build?
    - REFUSAL REASONS BUILT 2026-09-10 (code, NOT committed per user): derivation
      refusal names its gate via out-param (mirrored / adopted-summary / no-header-row /
      no-month-columns / no-desc-col / bad-desc-letter / bad-month-letters / invalid-map /
      unproven-spans / no-matching-sheet); controller logs the reason per skip; store
      outcomes already logged. Test pins all six user-reachable reasons. Full 1117 passed /
      same 7 pre-existing; JS 90/92. NO commit per user.
    - B-5 SPECIMEN BANKED 2026-09-10 (git-ignored sampledocs/SCurve-Test-6Stage-Novel-Layout-Specimen.xlsx, never commit — 2c-specimen precedent).
      Fourth family, novel on BOTH axes: desc "SCHEDULED TASKS" (misses strict+loose+fuzzy
      vocab) + months "STAGE 1..6" (match no month rule, strict or relaxed). 4 activities,
      contract 1,200,000, single columns, true curve 5/20/40/60/80/100. Verified by
      standalone service probe: rules 422 "No S-curve table found" AND auto-map null —
      a genuine B candidate (only AI assist or hand-teaching can take it). Browser proof
      script: import → 422 + capture → AI assist → proposed ai-derived row → approve →
      re-import parses with learned-layout note, no AI call.
    - REVERSAL APPROVED 2026-09-10 (user: learn columns regardless of mirror).
      Corrected principle: column trust ≠ month trust — maps store POSITIONS, and
      buildMonths recomputes from rows, so correct columns yield correct curves whatever
      the months claimed. Mirror/adopted status describes months (already handled: mirror
      note, prefer-math swap, fallback flag), never columns. 2c does NOT cover this
      (SUM-vs-MAX inside a span vs which-columns mapping — adjacent, not overlapping);
      guards are sig + numbers-proof + approval + the new inline column display.
      Retrospective banked: mirror/adopted skips were caution-first placeholders that
      stood unreviewed until the 6-stage runs made the skip visible; refusal substituted
      for reviewability, now fixed by displaying columns pre-approval.
      Build (separate order below): drop G1/G2 + retire the two reason codes, desk inline
      map summary (DESC=C · M1=I+J), flip the 3 encoding tests + add mirror/adopted-learn
      tests, suites, no commit until ordered.

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
    -     Tests tests/Feature/Services/AI/GeminiDocumentParserServiceTest.php: 7/7 green
      (success-shape + normalization, fences, malformed/list/500 decline, empty-key sends
      nothing, all-null + empty-images decline, 3-image cap asserted on the wire, invalid
      values → null). First Http::fake() use in the suite. No commit without OK.

  2026-09-08 — AI-5 BROWSER-PROVEN on merge15-baseline (user test4 run; formal log close).
    Evidence (all user-reported, all passing): banner offered on the weak test4 run → one
    assist call → merged with AI badges on Location, Description, ABC, Budget Source, user
    verdict "the ai fill is accurate" → pooled amber disagree on price (4204128.84 vs
    4323196.77) intact alongside AI fills → rule-kept boxes (dates, title) untouched →
    Apply → Learned Keywords desk shows the new rows with the AI-assisted badge (AI-4
    sourcing verified live). Blinded-ABC exclusion intact throughout (ABC filled BY AI while
    the hardcoded path slept). Trivia (correctable, not defects): title "PE" prefix artifact
    (OCR misread propagated); "Fiduciary Fund" flagged at Apply by the admin-only dropdown
    rule (user decision: Budget Source/Category stay manual-as-today, zero change).
    AI-5 CLOSED (normal-doc). AI-6 (S-curve slice) stays parked. No commit in this entry.

  2026-09-08 — AI-3 + AI-4 BUILT on merge15-baseline (code, NOT committed; folded — AI-4
  sourcing rides the AI-3 merge so provenance never corrupts; no push per user).
    - Trigger: core shouldOfferAiAssist(parsed, disagree) pure + exported (fills≤3 OR
      confidence<40 OR unresolved disagree; clean incl. blinded-clean stays silent).
      Fixture locks all five branches.
    - Banner: dynamic amber opt-in in showAutofillPreview (S1 remove-first, contractor
      hidden); empty-warning path gets the same button (opens a preview on emptyBaseline
      snapshot so AI-kept rows harvest with AI source).
    - Slice: buildAiSlices(2) reuses the snapshot re-render (stashed Files + live rotations,
      150 DPI ≈ scale 2.08, 4000px absurd-guard); weakest pages first (unread scanned, then
      scanned, then first pages); one POST per click (busy + stale-run guarded).
    - Merge: AI values into EMPTY boxes only (v1 scope — rule values kept, user edits freely),
      per-field AI-assisted badge, window._autofillAiAssisted stamps; success/failure notes
      inline; resets in cancel path + per run.
    - Sourcing: migration source column (correction default) + model SOURCES + collect
      validation/store + list mapping + desk Origin badge column (shared with parked P1).
      Harvest tags AI-touched rows; miner/caps/trust unchanged.
    - Tests: +1 core trigger fixture; +1 PHP source test (AI-assisted stored/listed, bogus
      rejected, omission defaults). JS 90/92 (2 intended blinding fails); combined AI suites
      25/25; Blade view:cache clean. No commit/push without OK.

  2026-09-08 — AI-2 BUILT on merge15-baseline (code, NOT committed; no UI yet).
    - Endpoint app/Http/Controllers/DocumentParserController.php::parseWithGemini(): exactly
      one source (images[] client slices | image file upload | image document_id), 201/422
      shapes mirroring SCurveImportController, per-slice + total byte budgets, PDFs declined
      with the slice instruction (no server renderer by design). Stored bytes read via the
      Storage disk (not full_path) so Storage::fake tests run the real code.
    - Routes POST /{staff,admin}/document/parse-gemini (role middleware blocks contractors);
      .env.example gains AI_API_KEY=/AI_MODEL= placeholders.
    - Incident (repeat of L1): stale bootstrap/cache/routes-v7.php hid the new routes (all 7
      endpoint tests failed pre-clear); `route:clear` fixed, 7/7 green after. Standing lesson:
      clear the route cache whenever routes are added (testing runbook note).
    - Tests tests/Feature/DocumentParserGeminiTest.php: 7/7 green (admin+staff success,
      source discipline, AI-miss 422, contractor redirect, image-upload/PDF-decline, stored
      image/PDF/ghost document_id). Combined AI suites 24/24 with keyword proposals (10/10).
      No commit without OK.
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

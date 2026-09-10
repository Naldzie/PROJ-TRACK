# AI PARSER KEY + MODEL SPLIT PLAN (E3)
# Parent refs: textfiles/document_parser_accuracy_improvement_plan.md (E2 entries),
#   textfiles/document_parser_ai_fallback_plan.md (E2/AI-6 notes)
# Date: September 10, 2026 — Status: BUILT + LIVE-PROVEN (committed this session; predictions untouched)
# Branch on build: merge15-baseline (config + service seam, Rule 12; no merge to main without OK)

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name / Client / Framework
  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).

Why this file exists
  The AI was born for prediction explanations + recommendations (monthly emails
  included). Document parsing (normal-doc slices) and Excel S-curve parsing (E2)
  are newer tenants sharing the SAME key and model: AI_API_KEY + AI_MODEL =
  gemini-flash-lite-latest (verified: .env:73, config/services.php:44-46).
  Live evidence (user, 2026-09-10) shows the same file computed correctly under
  full Flash (webapp, incl. row-14 flag) while Lite copies the summary — the model,
  not the prompt, is now the prime suspect. This file plans the split.

Frontend convention (IMPORTANT)
  No UI changes in this plan (config + service seam only).

===============================================================================
  2. STATUS SUMMARY
===============================================================================

  Prediction AI (Lite, shared key)              ✅ LIVE (unchanged by this plan)
  Parser AI (Lite, shared key)                  ✅ LIVE (moves to Flash + own key)
  E2 validation stack (parity/presence/swap)    ✅ BUILT, NOT committed (unchanged)
  Key/model split (THIS FILE)                   ⬜ PLANNED — no code yet

===============================================================================
  3. PROBLEM STATEMENT
===============================================================================

  3.1 One key, one model for two jobs.
    Parsing bursts (project-creation season) and prediction emails share
    AI_API_KEY + gemini-flash-lite-latest. No isolation, no per-feature cost
    visibility, and the weaker model serves the harder job (multi-step table
    math: add months, divide by the right total, cumulative, hold summary apart).

  3.2 Dead model IDs die silently.
    During prediction dev some model IDs "did not work" — almost certainly
    retired/renamed IDs returning 404. Both senders fail closed to '' with no
    status logged (GeminiDocumentParserService::callGeminiText,
    callGeminiWithImages), so a dead model looks identical to "AI found nothing".

===============================================================================
  4. DECISIONS BANKED (user, plain words)
===============================================================================

  Decision K1 — Parser key = SECOND key in the INSTITUTE account/project.
    Context: current (only) key belongs to the user's college Gmail; no PMU Gmail
    exists; user weighed a 2nd personal account vs institute second key.
    Decision: institute second key. Rationale banked: (1) quota isolation already
    comes from the model split (Flash vs Lite meters are independent even
    same-project — usage here is far below free-tier daily counts); (2) one academic
    identity keeps ownership/defense story clean (personal accounts get lost to
    graduation/password/phone changes); (3) same-project hygiene avoids any
    quota-multiplication gray areas; (4) PMU-owned migration later is .env-only.
    Consequences: parser = new key (fresh per-key metrics from zero); predictions key
    untouched; never commit keys (.env stays out of git, only .env.example placeholders).

  Decision K2 — Flash for parsing, Lite stays for predictions.
    Context: webapp full-Flash computed correctly; system Lite copies.
    Decision: GeminiDocumentParserService (both slices) reads the parser model;
    prediction services (AIExplanationService, AIRecommendationService) stay on Lite.
    Rationale banked: public benchmarks position Lite for simple/latency-sensitive work
    and Flash for longer multi-step tasks — our table math is the latter.

  Decision K3 — Exact Flash ID comes from the list-call, never guessed.
    Context: retired IDs 404 silently (see §3.2).
    Decision: user runs GET v1beta/models?key=KEY per key, picks the full-Flash ID
    with generateContent support, hands it over as AI_PARSER_MODEL. Env-driven so a
    different ID needs no code change.

  Decision K4 — Dead models must be diagnosable.
    Decision: log the HTTP status on every failed AI call (both senders); the same
    list-call doubles as key-health check. One-line diagnosis forever after.

===============================================================================
  5. IMPLEMENTATION STEPS (in order; each proved before the next)
===============================================================================

  E3-1 Config + service seam.
    - config/services.php: gemini += parser_key (env AI_PARSER_API_KEY, fallback
      AI_API_KEY) + parser_model (env AI_PARSER_MODEL, fallback AI_MODEL).
    - GeminiDocumentParserService: private parserKey()/parserModel() helpers used by
      BOTH senders (callGeminiText, callGeminiWithImages) — single seam, no per-call
      plumbing. Predictions services untouched (still AI_API_KEY/AI_MODEL).
    - .env.example: AI_PARSER_API_KEY= / AI_PARSER_MODEL= placeholders (values NEVER
      committed; user pastes the real key into local .env only).
    - Status-code debug log in both senders (Decision K4): non-2xx/throw logs status
      + model id at debug level (best-effort, never breaks calls).

  E3-2 Tests (Http::fake + config overrides, existing idiom).
    - Parser key/model ride the wire (assert on outgoing payload auth + model field).
    - Fallback reads (parser env missing → main key/model used).
    - Prediction sender still uses main key/model (no cross-contamination).
    - Full + JS suites with clean-tree regression check (7 pre-existing).

  E3-3 Live re-test protocol (user, browser).
    Same 10-month file, wiped-table conditions, 2 runs on Flash: expect computed
    months agreeing with map math + log line + notes pasted back here either way.
    If Flash copies → model variable eliminated with evidence; slice becomes the
    suspect again (presence gate + labeled sections already waiting).

===============================================================================
  6. SESSION LOGS
===============================================================================

  2026-09-10 — File created (plan only, no code).
    - Ordered by user: own md file for the key/model split implementation.
    - Evidence chain: .env:73 (gemini-flash-lite-latest) + services.php:44-46 (single
      shared key/model) verified read-only; public Flash-vs-Lite positioning checked
      (Lite = simple/latency-sensitive tier, Flash = multi-step all-rounder);
      webapp-Flash vs system-Lite trial banked as prime-suspect evidence.
    - Quota semantics clarified: limits counted per model per project (keys are
      passwords, not meters) — Lite-exhausted never blocks Flash; same-project second
      key = hygiene/tracking, separate project = real pool split.
    - Awaiting: exact Flash ID (user list-call) + parser key provisioning (user pastes
      into .env; unproducible by assistant) + build go-ahead. No merge without OK.

  2026-09-10 — E3-1 + E1-3 CLOSED WITH LIVE EVIDENCE (user test; commit + push ordered).
    - Parser key LIVE: institute second key (same project, per K1). The earlier 400 on the
      new key was a paste/enablement slip on the user side (proven by subsequent success
      on the same key — no code or plan change needed for it).
    - Parser model LIVE: gemini-flash-latest (DEVIATION from the pinned-2.5 recommendation,
      user-approved): pinned gemini-2.5-flash 404s (retired) while the alias answers. DRIFT
      CAVEAT (user-accepted): the alias floats — if AI behavior ever drifts mysteriously,
      alias repointing is suspect #1 (same class as the Lite-alias note for predictions).
      Revisit pinning only if drift is ever observed; the status log will name it.
    - RE-TEST PROTOCOL PASSED (E3-3, user browser, wiped-table conditions): full Flash
      returned the TRUE curve (5,10,20,30,50,70,80,90,95,100) with four honest gap warnings
      (M5–M8) and a legitimately-earned Checked badge — no copy, no silence. Model verdict
      CLOSED: Lite copied, Flash computes (same file/prompt/slice/validation).
    - Standing queue reminder: A point-and-teach desk → B learn-from-AI maps,
      both behind the AI-verification batch.
    - Config: services.php gemini += parser_key (AI_PARSER_API_KEY → AI_API_KEY) +
      parser_model (AI_PARSER_MODEL → AI_MODEL → legacy default); runtime fallback in
      the service too (direct config overrides + cached configs resolve identically).
    - Service: parserKey()/parserModel() helpers wired into BOTH senders
      (callGeminiText, callGeminiWithImages); predictions services untouched (Lite).
      Status-code debug log on every failure path (both senders) — dead IDs/keys
      diagnose in one line (Decision K4).
    - .env.example: AI_PARSER_API_KEY= / AI_PARSER_MODEL=gemini-2.5-flash placeholders
      (values never committed).
    - Caught pre-green (all fixed before green, no reverts): (1) re-calling Http::fake()
      does NOT replace the stub as assumed — second fake kept serving the first stub,
      so the project-slice half validated months-JSON as all-null (proven via reflection
      probe + recorded() log); split into single-fake-per-test. Lesson banked: one fake
      per test. (2) My own edit clipped two test bodies (decline test, agree test) —
      restored immediately, suites proved each. (3) Sender-label mixup: an 'images' tag
      landed in the text sender — corrected, both senders verified by grep.
    - Tests: +3 (excel-wire creds, images-wire creds, shared fallback). Suites: AI service
      27/27; combined parser suites 89/89. Full-suite + JS + clean-tree check due next.
    - User action outstanding: paste parser key + AI_PARSER_MODEL=gemini-2.5-flash into
      local .env, restart serve; re-test protocol (E3-3) is the user's step.

===============================================================================
  7. OPEN QUESTIONS / STILL TO RESOLVE
===============================================================================

  - Exact full-Flash model ID (user list-call outstanding).
  - Parser key provisioning (user-side; assistant cannot create Google keys).
  - Paid tier later (widens quotas until this file is moot; revisit only if free-tier
    RPDs are ever actually hit — current usage is orders of magnitude below).

===============================================================================
  8. FILES TO TOUCH (on build — NOT NOW)
===============================================================================

  - config/services.php (parser_key/parser_model entries)
  - app/Services/AI/GeminiDocumentParserService.php (helpers + both senders + status log)
  - .env.example (placeholders only)
  - tests/Feature/Services/AI/GeminiDocumentParserServiceTest.php (wire + fallback tests)
  - This file (build record on completion)

===============================================================================
  9. CONVERSATION CONTINUATION GUIDE
===============================================================================

  For the next session:
    1. This file is the single home for E3; E2 plans stay history + pointer.
    2. Do NOT build until the Flash ID + key are in hand (K3); fallbacks keep
       everything working if env is missing.
    3. Blinding stays ON; no merge without explicit user confirmation (Rule 12).
    4. Standing queue reminder: A point-and-teach desk → B learn-from-AI maps,
       both behind the AI-verification batch.

===============================================================================
  END OF PLAN
===============================================================================

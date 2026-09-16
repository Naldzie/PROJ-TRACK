# LOCAL LLM FOR THE S-CURVE PARSER (REPLACING THE GEMINI TIER)
# Parent refs: textfiles/document_parser_ai_fallback_plan.md (AI-1..AI-6 + E2 — the Excel/normal-doc AI)
#              textfiles/document_parser_pdf_ai_fallback_plan.md (F-A..F-D + B — the PDF image-slice AI)
#              textfiles/document_parser_key_model_split_plan.md (E3 — the parser's own key/model, which is
#                what makes this swappable without touching predictions)
#              textfiles/document_parser_pdf_teach_grid_plan.md (§13, 2026-09-16 — the usage measurement
#                this file is built on, logged there in full)
# Date: September 16, 2026
# Status: 🔄 INVESTIGATION ONLY — NO CODE, NOTHING DECIDED. Raised by the user after
#         frequent Gemini 503s, and because the capstone PANEL and the CONSULTANT are
#         likely to require a campus-hosted model instead of a third-party API.
#         The usage measurement IS DONE (§4) and it supports acting. What it does NOT
#         support is acting in the order first proposed — see §5 and §8.
# Branch on build: phase-<n>-local-llm-parser (Rule 12; no merge to main without OK)

===============================================================================
  0. READ THIS FIRST (handoff in 60 seconds)
===============================================================================

  WHAT THIS IS
    Whether to replace the parser's Gemini tier with a locally/campus-hosted model,
    and if so, in what order to do it. Written because the question is now
    institutional, not just technical: the panel may require it and the consultant has
    ruled out paid services.

  THE ONE NUMBER THAT MATTERS
    Over seven days of real browser use the parser's AI tier answered 134 of 549
    calls. In the last two days: ONE response in 59 calls. The tier is effectively
    dead. (§4 has the full table and the failure causes.)

  THE ONE FACT THAT STOPS THIS BEING AN EMERGENCY
    Nothing in the sample corpus needs the AI. The roof family reads its true curve
    with ZERO AI, the one-page BUHI reads its exact Excel curve through a taught
    layout, 6-stage and 10-month read exactly on first upload, and every Excel file
    reads through phpspreadsheet. The AI path is OPT-IN — a reviewer presses
    "Cross-check with AI?" or "Try AI assist?". So all 549 calls were deliberate
    presses during development. A 503 degrades a second opinion; it has never blocked
    a baseline.

  THE CORRECTION WORTH CARRYING INTO THE PANEL CONVERSATION
    The stated reason for wanting an LLM is that a public college's contractors each
    bring their own S-curve layout. THAT PROBLEM IS ALREADY SOLVED, AND NOT BY AI —
    it is what the capture → teach → approve learning loop is for. A novel layout is
    taught ONCE and then reads free, offline and deterministically for every later
    upload from that contractor. That is a stronger answer to a panel than "we ask a
    model": auditable, repeatable, demonstrable, and free per call. The AI tier exists
    for a layout nobody has taught YET, and for the P5 cross-check.

  ORDER OF WORK IF THIS IS ORDERED (rationale in §5)
    1. Measure the MODEL on the two real specimens — before any hosting decision.
    2. Make the endpoint configurable (one service, three hardcoded URLs).
    3. Only then choose where it runs.

  HARD CONSTRAINTS ALREADY ESTABLISHED
    - No paid services (consultant; the client will not pay).
    - Predictions / recommendations / monthly email AI STAY on Gemini. The E3 key and
      model split already separates them, so this swap cannot disturb them.
    - Rule 12 applies: new branch, no merge to main without explicit confirmation.
    - Rule 10: ask before building each step.

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

  System Name / Client / Framework
    Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
    Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).

  Why this file exists
    The AI fallback plans (AI-1..AI-6, F-A..F-D, E2..E6) all assume Gemini. The
    question of a DIFFERENT BACKEND is new, has its own constraints (campus hosting,
    no paid services, a panel to satisfy) and its own measurements. It does not belong
    inside any of those files.

  Frontend convention (IMPORTANT)
    App UI JS/CSS lives in public/ via asset()/$jsv() — never @vite, never resources/
    for new feature scripts. (No UI work is expected in this plan.)

===============================================================================
  2. PROBLEM STATEMENT
===============================================================================

  (a) THE IMMEDIATE ONE — the Gemini free tier is failing most of the time. Measured
      in §4. The E4 fallback ladder already walks four models and still ends in
      nothing, because the problem is provider-wide capacity, not a bad model id.

  (b) THE INSTITUTIONAL ONE (user, 2026-09-16) — the capstone panel may require the
      system to run on a campus-hosted model rather than a third-party API, and the
      consultant has asked the team to try it. The consultant has also ruled out any
      PAID service, because the client (a government/public college) will not pay for
      one. Frequent 503s in front of a panel are themselves a risk.

  (c) THE REASON GIVEN FOR WANTING AN LLM AT ALL — the college deals with many
      contractors, each with their own S-curve layout and no fixed format.
      See §0: this is the learning loop's job, and the loop already does it. Recording
      it here so the argument is not re-litigated from scratch next session.

===============================================================================
  3. WHERE THE AI ACTUALLY SITS TODAY (so a swap can be scoped)
===============================================================================

  THE TIER ORDER (unchanged, user-approved, ascending cost/risk)
    Tier 1 local rules (free/offline) → OCR paths (free/in-browser) → the L1-L3 and
    E1/E1-PDF learning loops → AI fallback → (nothing after).

  THE THREE AI PARSER PATHS, all sharing one ladder walk (climbFallbackLadder)
    normal-doc slices    document parsing for the create-project wizard
    E2 parseExcelSlice   weak/novel Excel S-curves
    F-A PDF image slices weak/novel PDF S-curves (at most 2 page images, cap 3)

  WHAT IS **NOT** IN SCOPE OF A SWAP
    Predictions, recommendations and the monthly AI email. E3 (2026-09-10) gave the
    parser its OWN key and model:
      config/services.php  gemini.key / gemini.model          -> predictions (untouched)
                           gemini.parser_key / parser_model   -> the parser (this plan)
    So the parser backend can change without any risk to the AI module that passed the
    title defense. This is the single most useful fact in this file.

  CONFIG THAT ALREADY EXISTS (.env)
    AI_PARSER_API_KEY / AI_PARSER_MODEL           the parser's own credentials
    AI_PARSER_FALLBACK_MODELS                     E4 ladder, comma-separated, in order
    AI_PARSER_TIME_BUDGET=50                      E5 wall-clock ceiling for ONE call
    AI_PARSER_IMAGE_TIMEOUT=15 / _TEXT_TIMEOUT=30 E6 per-rung timeouts
    Live at the time of writing: AI_PARSER_MODEL=gemini-flash-latest with fallbacks
    gemini-3.7-flash, gemini-3.6-flash, gemini-3.5-flash.

  THE ONE THING THAT BLOCKS ANY OTHER BACKEND
    The endpoint is HARDCODED IN THREE PLACES in
    app/Services/AI/GeminiDocumentParserService.php — lines 1722, 1889 and 2016:
      https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}
    There is no base-URL setting and no request/response abstraction: the payload is
    Gemini-shaped (contents / parts / inlineData) and so is the parsing of the reply.

===============================================================================
  4. MEASUREMENT — IS THE AI TIER WORTH RESCUING? ✅ DONE 2026-09-16
===============================================================================

  METHOD: storage/logs/laravel.log (5.4 MB, 2026-09-10 .. 09-16). The PHPUnit suite
  writes to the SAME file, so everything below is filtered to `local.` (real browser
  use). This matters: unfiltered the log shows 3,980 parser failures, of which 3,565
  are the test suite. Any future re-run must filter the same way.

  REAL USE, SEVEN DAYS
    date    failed calls   got a response   ladder walked
    09-10        45              20               0
    09-11        60              28               0
    09-12        71              39               0
    09-13        62              19              26
    09-14       119              27              52
    09-15        34               0              12
    09-16        24               1               9
    TOTAL       415             134              99
    Also: E5 time-budget stops 45 · E6 rung promotions 10.

  WHY THE CALLS FAILED (local only)
    503 .......... 247    capacity — the one being reported
    throwable .... 125    transport / timeout
    429 ........... 41    quota (on those days the free-tier LIMIT was binding, not capacity)
    404 ............ 2    dead model id

  FINDINGS
    1. NOT A PERCEPTION. 134 of 549 calls answered over the week (24%); ONE of 59 in
       the last two days (~2%). The 2026-09-15 plan note that "the AI service returned
       503 all session" was literally true, and 09-16 is no better.
    2. THE LADDER WORKS AND IS NOT ENOUGH. 99 walks, 10 promotions, still nothing.
       Adding more Gemini rungs cannot fix provider-wide capacity.
    3. THE WAIT IS THE WORST PART. 45 times the ladder spent its whole 50-second
       budget and stopped with no answer — a reviewer watching a spinner for most of a
       minute for nothing.
    4. AND YET NOTHING DEPENDS ON IT (see §0). Both halves matter: the tier is dead,
       and the system does not currently need it.

===============================================================================
  5. CHOSEN DIRECTION (PROPOSED — needs an explicit order, Rule 10)
===============================================================================

  STEP 1 — MEASURE THE MODEL, NOT THE HOSTING.  ⬜ NOT STARTED
    Whether an open vision model is good enough on THESE files is a one-off test, and
    the benchmark already exists: known-truth curves for the roof
    (6.32/24.31/42.30/59.71/77.03/99.14/100, counts 4,4,4,4,3,4,3) and the one-page
    BUHI (1.12 … 100, counts 8,11,12,14,19,20,32,34,38,43,41,41), plus every script in
    textfiles/pdf_ai_loop_proof_tests.md. Run the same page images the app would send
    (buildSCurvePdfSlices picks at most 2, table pages first) and compare.
    IF A LOCAL MODEL CANNOT MATCH WHAT GEMINI MANAGED ON THOSE, THE HOSTING QUESTION
    IS MOOT. Do this before spending anything on infrastructure.
    Candidate models (from the user's own research, recorded as candidates, NOT as
    endorsements — none has been tested here):
      Qwen2.5-VL 7B      reported strongest open model for charts/tables/layout
      MiniCPM-V 2.6 8B   reported similar, slightly lighter
      LLaVA-OneVision 7B reported weaker on precise numeric extraction
    Note the P5 cross-check already catches a contradicting AI answer using the file
    itself, so a mediocre model is not dangerous — but it is also not useful.

  STEP 2 — MAKE THE ENDPOINT CONFIGURABLE.  ⬜ NOT STARTED
    Lift the three hardcoded URLs (§3) to config, and add an OpenAI-compatible
    request/response shape beside the Gemini one. Contained to ONE service; no parser
    logic, no prompt changes, no UI. That converts "replace Gemini with the campus
    model" from a rewrite into a config change, and lets any backend be tried without
    committing to one — Ollama, a hosted Qwen endpoint, OpenRouter, a campus box.
    WORTH HAVING BEFORE THE PANEL ASKS, NOT AFTER. It is also the only step that is
    useful no matter which model or host is eventually chosen.
    Watch when building: the reply parser is Gemini-shaped too; the E4 ladder's
    transport checks read Gemini status codes; E5/E6 budgets are per call and must
    keep applying.

  STEP 3 — CHOOSE WHERE IT RUNS.  ⬜ NOT STARTED, and deliberately last.
    Only meaningful once steps 1 and 2 answer "which model" and "how do we call it".

  WHY THIS ORDER
    The first instinct was to stand up hosting and point the app at it. That spends
    the most effort on the least certain part. Steps 1 and 2 are cheap, independent,
    and each is useful on its own: step 1 can kill the whole idea for free, and step 2
    pays off even if Gemini is kept (any future provider becomes a config line).

===============================================================================
  6. HOSTING NOTES (recorded while fresh — NOT a recommendation)
===============================================================================

  GOOGLE CLOUD "FREE TIER" HAS NO GPU. Always Free is an e2-micro instance; there is
  no GPU in it. The $300 / 90-day trial can technically run GPUs, but trial accounts
  normally carry ZERO GPU quota until the account is upgraded, and the quota request
  is often declined. Plan around this rather than discovering it late.

  COLAB FREE gives a T4 (16 GB) and will run a 7B VL model in 4-bit, but it is an
  INTERACTIVE NOTEBOOK: no stable URL, sessions drop after idling, GPU access is
  rationed daily. Pointing Laravel at it needs a tunnel that will die mid-demo. Fine
  for step 1 (a one-off measurement). Not something a defense should depend on.

  CAMPUS SERVER SIZING. A 7B vision model in 4-bit wants roughly 6–8 GB VRAM. On a
  CPU-only server a page image takes minutes per call, which E5's 50-second budget
  would cut off anyway — so "campus server" has to mean a GPU, or the image path stays
  remote while only text paths move local.

  A POINT IN LOCAL'S FAVOUR THAT IS NOT ABOUT COST: these are real contractor
  documents. A campus-hosted model keeps them on campus, which is a stronger data
  boundary than any third-party API and a good answer if the panel asks about it.

===============================================================================
  7. WHAT NOT TO DO
===============================================================================

  - DO NOT add an LLM to the EXCEL path to "interpret" numbers. phpspreadsheet already
    reads those workbooks exactly; a model can only make that worse. (The advice that
    prompted this plan said the same thing, and it is right.)
  - DO NOT replace pdf.js with pdfplumber-style text extraction. pdf.js gives x/y per
    token, which is what every layout rule, the teach grid and the whole capture/
    consult path are built on. smalot/pdfparser was rejected for exactly this reason
    (it strips bounding boxes) — see the accuracy plan.
  - DO NOT let a local model become tier 1. The order in §3 is deliberate and
    user-approved: rules, then the loop, then AI. A local model changes WHO answers
    the last tier, not WHERE the tier sits.
  - DO NOT touch gemini.key / gemini.model. Those are the prediction module's.

===============================================================================
  8. DECISION LOG
===============================================================================

  Decision 1 — Measure the usage before deciding anything (user, 2026-09-16).
    Context: a local-LLM migration was proposed in response to frequent 503s.
    Decision: run the measurement first (§4).
    Rationale: the AI tier is opt-in and the corpus reads offline, so it was not known
      whether the tier was load-bearing at all.
    Consequence: it is not load-bearing — but it IS effectively dead (1 answer in 59
      calls over two days), which supports acting, just not urgently and not blindly.

  Decision 2 — Hosting is the LAST step, not the first (recommended 2026-09-16, not
    yet ordered).
    Options: (a) stand up Colab/GCP hosting and point the app at it; (b) measure the
      model first, then make the endpoint configurable, then choose hosting.
    Decision: (b) proposed.
    Rationale: (a) spends the most effort on the least certain part, and Google's free
      tiers do not offer what it assumes (§6). Step 1 can kill the idea for free and
      step 2 is useful even if Gemini is kept.

  Decision 3 — The "no fixed format" argument is answered by the learning loop
    (recorded 2026-09-16).
    Context: the consultant's stated reason for wanting an LLM.
    Rationale: capture → teach → approve already makes a novel contractor layout read
      free, offline and deterministically after ONE teaching. It is auditable and
      demonstrable in a way a model's answer is not.
    Consequence: the local LLM is justified by RESILIENCE and DATA BOUNDARY, not by
      layout variety. Argue it on those grounds.

===============================================================================
  9. OPEN QUESTIONS / STILL TO RESOLVE
===============================================================================

  - Does the panel actually require this, or is it an expectation the team is
    anticipating? The answer changes the priority completely. WORTH ASKING DIRECTLY.
  - Does the campus server have (or can it get) a GPU? Without one, only the text
    paths can move local (§6).
  - Is an OpenAI-compatible shape enough for the chosen runtime, or does it need a
    native client? (Ollama and vLLM both speak OpenAI-compatible; llama.cpp's server
    does too.)
  - Who maintains the campus model after the capstone ends? A dependency nobody owns
    is worse than an API that sometimes 503s.

===============================================================================
  10. FILES THAT WOULD BE TOUCHED (step 2)
===============================================================================

  app/Services/AI/GeminiDocumentParserService.php   the three URLs + request/response shape
  config/services.php                               a base-URL / provider setting
  .env.example                                      the new keys, documented
  tests/                                            a provider-shape test; the existing
                                                    parser tests must stay green
  NOTHING in public/js — this is a server-side seam. No UI change is expected.

===============================================================================
  11. CONVERSATION CONTINUATION GUIDE
===============================================================================

  1. Read §0, then §4. The measurement is the justification; do not redo it without
     filtering the log to `local.` (the suite shares the file).
  2. Nothing here is built. Ask for an explicit order before starting any step (Rule 10).
  3. Step 1 needs no code in this repo — it is a model test against the known-truth
     curves in §5. Do it before anything else.
  4. Keep predictions out of it (§3). The E3 split is what makes this safe.
  5. Log results HERE before committing, and re-check the standing constraints in
     AGENTS.md (blinding stays on; no merge to main without confirmation).

===============================================================================
  END OF PLAN
===============================================================================

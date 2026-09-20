# LOCAL LLM FOR THE S-CURVE PARSER (REPLACING THE GEMINI TIER)
# Parent refs: textfiles/document_parser_ai_fallback_plan.md (AI-1..AI-6 + E2 — the Excel/normal-doc AI)
#              textfiles/document_parser_pdf_ai_fallback_plan.md (F-A..F-D + B — the PDF image-slice AI)
#              textfiles/document_parser_key_model_split_plan.md (E3 — the parser's own key/model, which is
#                what makes this swappable without touching predictions)
#              textfiles/document_parser_pdf_teach_grid_plan.md (§13, 2026-09-16 — the usage measurement
#                this file is built on, logged there in full)
# Date: September 16, 2026
# Status: ⏸ PAUSED AFTER STEP 1 (2026-09-16 close) — NO APP CODE.
#         §4 usage measurement: done. §5 step 1 (measure the model): DONE, and it FAILED —
#         Qwen2.5-VL 3B / 7B on the PDF specimens and Qwen2.5-7B-Instruct on the Excel
#         specimens, all on a free Colab T4: no model computed a curve from activity
#         rows, and nearly every call exceeded the 50 s budget. Conclusion for the panel
#         is in §5. Steps 2 (configurable endpoint) and 3 (hosting) not ordered.
#         Open, needs an order: the prompts' example contractAmount is BUHI's real one.
#         2026-09-17: STEP 1c "TEST A" (the model LOCATES, the app READS) is BUILT and
#         waiting for the user's Colab run — see §5, STEP 1c. No app code.
#         Notebooks + prompts: C:\temp\opencode\colab_*.ipynb, colab_excel\; results:
#         sampledocs/vlm_results_*.json, llm_excel_results_*.json (git-ignored).
#         2026-09-18: ⏬ DEPRIORITISED. The client confirmed S-curves arrive as the ORIGINAL
#         EXCEL (so the Excel path needs no model at all) and that their NORMAL documents
#         are mainly SCANNED (so if an AI tier ever revives, it belongs on the normal-doc
#         path, not here). Active file is textfiles/pilot_testing_readiness_plan.md; the
#         AI tier's ~2% answer rate is a pilot UX decision there (item P5), not a hosting
#         question. See §0's 2026-09-18 note and Decision 4.
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
    ⚠⚠ SCOPE CORRECTION 2026-09-20 — READ THIS BEFORE QUOTING THE NUMBER BELOW.
    The user states that this failure rate is the S-CURVE PDF path, which is what was
    being developed and tested through that window. For NORMAL DOCUMENTS they see the
    busy-server error only a handful of times. So "the parser's AI tier is effectively
    dead" is NOT a fact about normal-doc parsing — which is the pilot's priority path.
    ⚠ AND THE SPLIT CANNOT BE RECOVERED FROM THE LOG AS IT STANDS. Both image paths
    (parseProjectSlice, the normal doc; parseSCurvePdfSlice, the S-curve) go through
    ONE sender tagged 'images' (GeminiDocumentParserService:1933), and that tag is all
    logGeminiFailure records. Worse, parseSCurvePdfSlice logs a success line and
    parseProjectSlice LOGS NOTHING, so the log is biased toward the S-curve path by
    construction. Full trace: ai_slice_selection_plan.md §10.
    ⇒ THE FIGURE STAYS HERE AS THE RECORD OF WHAT WAS MEASURED, but it must be quoted
      as "the image paths in aggregate, dominated by S-curve PDF testing", never as a
      normal-doc availability rate. Re-measuring needs the path tagging first
      (ai_slice_selection_plan.md §10.4, and pilot plan P5(a) is blocked on it).
    THE ORIGINAL NOTE, kept:
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

  ➜ 2026-09-18 — TWO CLIENT FACTS THAT CHANGE THIS FILE'S WEIGHT (read before proposing
    any further model test)
      1. THE PMU MAINLY HAS THE ORIGINAL EXCEL for S-curves. §7 already said not to put
         an LLM on the Excel path because phpspreadsheet reads those workbooks exactly.
         That was a design argument; it is now also the normal case. The S-curve half of
         this plan has almost no problem left to solve.
      2. THEIR NORMAL DOCUMENTS ARE MAINLY SCANNED — physical copies, scanned in, often
         with no text layer at all. IF an AI tier is ever worth reviving, THIS is where
         it would earn its place: the normal-doc slice path over scanned pages, not the
         S-curve path. Note what that implies for a local model — it would be doing OCR-
         grade vision work on photographed/scanned paper, which is harder than reading a
         clean digital table, and step 1 already showed 3B/7B failing the CLEAN case.
    PRIORITY: this file is not the active work. Pilot-testing readiness is —
    textfiles/pilot_testing_readiness_plan.md. The AI tier's availability appears there
    as item P5 (what "Try AI assist" should do when the tier answers ~2% of calls), which
    is a UX decision to make before the pilot, not a hosting decision.
    STILL OWED: the STEP 1c "Test A" Colab run (built 2026-09-17, §5). Unblocked and
    cheap, but not urgent — and its result cannot change §4's measurement or §0's
    conclusion.

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

  STEP 1 — MEASURE THE MODEL, NOT THE HOSTING.  ✅ DONE — ❌ BOTH 3B AND 7B FAIL
    (Qwen2.5-VL on a free T4; detail below. Larger open models need a 24-80 GB GPU,
    which no free tier offers — so on the constraints set, the local-model tier is not
    viable for PDF S-curve reading.)

    HOW IT IS RUN (2026-09-16): C:\temp\opencode\colab_vlm_scurve_test.ipynb (source
    colab_vlm_scurve_test.src.py, built by build_ipynb.mjs), on Colab free T4 (16 GB).
    Verbatim buildSCurvePdfPrompt; pages rendered like the app (~150 DPI, 4000 px cap,
    max 2 pages; page text via PyMuPDF instead of pdf.js — close, not identical);
    scored against the Excel truth, both the stated curve and the curve recomputed from
    monthlyAmounts / contractAmount. Results land in sampledocs/vlm_results_<model>.json
    (git-ignored).

    RESULT — Qwen2.5-VL-3B-Instruct (MAX_PIXELS lowered to 2,000,000) ❌ FAIL ON ALL THREE
      specimen     secs  months  stated curve               recomputed         counts
      roof 2-page   103   8 (7)  12.5, 25, 37.5 … 100       0.15 … 5.52         1 each (4,4,4,4,3,4,3)
      roof 1-page    74   8 (7)  12.5, 25, 37.5 … 100       12.41 … 446.69      1 each
      BUHI 1-page    85   6 (12) 12.5, 25 … 75              0.15 … 3.22         1-2 (8 … 41)
      It did not read the tables. It returned the PROMPT'S OWN EXAMPLE, extended:
      12.5-point steps from the example's "12.5", "Mobilization Works" from the example,
      monthlyAmounts 50,000 / 100,000 / … from the example's 50000, and on two files the
      example's contractAmount 32,588,531.43. Most activity names are invented
      ("Site Preparation", "Foundation Works", "Final Inspection"); a few BUHI names look
      lifted from the machine-read words. Every call also ran 74-103 s on a T4 — past
      the app's 50 s budget before accuracy is even considered.
      Would the app have been harmed? No: the printed-words check drops invented names,
      the recompute check refuses stated-vs-amounts disagreement, and P5 lets the file
      overrule the AI. Useless, not dangerous — exactly the §5 prediction.
      VERDICT: 3B is out. An on-device 3B model is not a viable parser tier.

    RESULT — Qwen2.5-VL-7B-Instruct (4-bit nf4, MAX_PIXELS 1,000,000) ❌ FAIL ON ALL THREE
      Memory: at 2,000,000 pixels the vision encoder's attention asked for 4.1 GB more
      than the T4 had (OOM); 1,000,000 ran. So BUHI's page was shown heavily downscaled
      — part of its failure is the T4, not only the model.
      specimen     secs  months  stated curve                       recomputed
      roof 1-page    47   5 (7)  6.32/24.31/42.30/59.71/77.03 EXACT   6.32 … 105.68
      roof 2-page    59   5 (7)  16/17/20/19/0 (not cumulative)       6.32 … 105.68
      BUHI 1-page   108  12 (12) 12.5 every month                     0.15 … 1.84
      What happened:
        roof 1-page — the exact numbers are the file's printed SUMMARY row copied into
          "months" (its "summary" is identical), which the prompt forbids; it stops at
          month 5 of 7; its monthlyAmounts sum past 100%. Not a computation from the
          activity rows.
        roof 2-page — summary partly read (6.32 / 17.99 / 42.3 …), months nonsense.
        BUHI — the prompt's example again (12.5, "Mobilization Works", 50,000 and the
          example contract, which is BUHI's real one — see the finding below).
        Names: one per month, and they are SECTION HEADINGS ("FACILITIES FOR ENGINEER",
          "OTHER GENERAL REQUIREMENTS"), not activities.
      Better than 3B at reading printed figures (it found the roof's summary row and its
      P1..P5 labels) — but the offline reader already reads that row itself (summary
      adoption / R2b). It adds nothing the app does not already do, and 2 of 3 calls ran
      past the 50 s budget.
      Would the app have been harmed? No: the summary-copy detection (mirror note /
      prefer-math) and P5 would catch the roof answer, and BUHI's is refused by the
      recompute check.

    CONCLUSION FOR THE PANEL CONVERSATION (2026-09-16)
      A free-tier open model (3B or 7B) cannot do this job: neither computed a curve
      from activity rows on any specimen. What does do it — offline, free, repeatable —
      is the rule reader plus the learning loop (and, as of today, the date-row rule on
      phase-21, which reads a day-column layout no model or teaching could). The
      honest options left for an AI tier are: keep Gemini as an optional second opinion,
      or test a much larger open model only if the campus can provide a 24 GB+ GPU.
      Step 2 (configurable endpoint) stays cheap and useful either way; not ordered.

    HOW FAITHFUL IS THE COLAB TEST TO A "LOCAL LLM"? (asked by the user, 2026-09-16)
      Same weights, same prompt, same page images as the app => the same answers a campus
      server running that model would give (hardware changes speed, not answers, apart
      from rounding). What does NOT match a real deployment, and could change results:
        - 7B ran 4-bit and at 1,000,000 pixels because of the T4's 15 GB. A 24 GB+ card
          could run it at full precision and full resolution — likely somewhat better on
          BUHI, unlikely to fix "copied the example".
        - Serving stack: Ollama / llama.cpp use their own quantization (GGUF) and image
          preprocessing — similar, not identical.
        - Page text via PyMuPDF instead of pdf.js.
        - THE PROMPT IS WRITTEN FOR GEMINI. It carries a concrete example object (12.5,
          "Mobilization Works", 50000, contract 32588531.43), and both small models copied
          it. That is a prompt-design problem as much as a model problem. A fairer small-
          model test would drop example numbers, constrain output to a JSON schema, and —
          better — ask the model only to TRANSCRIBE (rows, month columns, figures) and let
          our code do the arithmetic, which small models are weakest at. Not built.
        - EXCEL (step 1b below): for Gemini the app attaches the real .xlsx; a local model
          cannot read one, so it would get only the text slice (40 rows / 12,000 chars).

    STEP 1b — EXCEL ON A LOCAL MODEL  ✅ RUN — ❌ Qwen2.5-7B-Instruct FAILS BOTH FILES

      RESULT (4-bit, free T4, chunked prefill of 1,024 tokens; the first attempt without
      chunking ran out of memory on BUHI's 6,684-token prompt)
      case               secs  months (truth)  what it returned
      BUHI A (app text)   308  24 (12)  curve 6.53 … 297.73 (past 100), no names,
                                        month columns F..AC one letter each
      BUHI B (whole)      895  25 (12)  the same wrong columns and curve; its own peso
                                        amounts give 3.09 … 110.1; the same two names
                                        repeated every month
      roof                 94   6 (7)   stated curve not cumulative (6.32, 17.99, 17.99,
                                        17.41 …); the CUMULATIVE row copied correctly into
                                        "summary" (6.3166 / 24.309 / 42.30 / 59.71 / 77.03);
                                        "monthlyAmounts" are running totals lifted from a
                                        summary row; month columns F..K (truth: the hidden
                                        AJ:AS block); names are section headings
      contract           BUHI's matches only because the prompt's example IS BUHI's
                         contract; the roof's 2,239,599.72 is a genuine read.
      Giving it the WHOLE sheet did not help — the columns and the arithmetic are wrong
      either way, and it took 15 minutes. Every call exceeded the 50 s budget (94-895 s).
      SAFETY CHECK (probe_llm_layouts.php): all three reported layouts go through the
      real deriveMapFromAiLayout and are REFUSED ('invalid-map'), so learn-from-AI could
      not have stored any of them. The AI answers themselves would be caught by the
      presence/homework checks (curve past 100, months past the sheet).
      VERDICT: same as the PDF side. A free-tier 7B model reads printed labels and can
      copy a printed row, but cannot find the month columns or do the sums. The Excel
      path already has an exact offline reader (phpspreadsheet) for both files — there
      is nothing for a local model to add here (§7 already said so).
      C:\temp\opencode\colab_llm_excel_test.ipynb (source .src.py) + the four prompts in
      C:\temp\opencode\colab_excel\, generated BY THE APP (dump_excel_prompts.php:
      reflection on buildSCurvePrompt, text-only variant):
        prompt_buhi_xlsx.txt       11,161 chars  as the app sends it (43 of the sheet's rows)
        prompt_buhi_xlsx_full.txt  20,872 chars  whole sheet (93 rows), no 12,000 cut
        prompt_roof_xlsx.txt        7,264 chars  roof sheet fits whole (A == B)
      Truth = the app's own rule reads (BUHI 1.12 … 100, counts 8 … 41; roof 6.32 … 100,
      counts 4,4,4,4,3,4,3, contract 2,239,599.72). The Excel prompt's example also
      carries BUHI's contract, so a correct BUHI contract proves nothing.

    ⚠ FINDING FROM THE SAME RUN — THE PROMPT'S EXAMPLE CONTRACT IS BUHI'S REAL ONE.
      buildSCurvePdfPrompt's example carries "contractAmount": 32588531.43, which is the
      one-page BUHI's true contract to the cent. A model that copies the example scores
      a "correct" contract on BUHI for free (3B did). Consequences: (1) any evaluation
      must not count BUHI's contract as evidence of reading; (2) the example should use
      a neutral figure. Changing it is a prompt change on a live Gemini path — needs an
      order, not done.
    STEP 1c — TEST A: THE MODEL LOCATES, THE APP READS  🔄 BUILT 2026-09-17, RUN OWED (user)
      WHY (user, 2026-09-17): another AI model advised using Qwen to EXTRACT, not to
      calculate. Correction recorded before building: the first test did not fail only on
      arithmetic. The model also copied the prompt's example, copied a summary row, and on
      Excel picked the WRONG MONTH COLUMNS — an extraction failure the split does not
      remove. So Test A asks the smallest extraction question there is: WHERE things are.
      No figure ever comes from the model; the app reads every number from the file.
      THREE QUESTIONS (20 calls, one Qwen2.5-VL-7B 4-bit run on a free T4)
        Excel layout  (6 workbooks) headerRow / descCol / contractCol / monthColumns on the
                      app's own 40-row slice -> deriveMapFromAiLayout -> the reader's MAP
                      path (forced by reflection, so rule-readable files are also read
                      strictly through the model's layout).
        PDF labels    (roof 2-page + 1-page) each month's printed header text ->
                      derivePdfLayoutFromAi -> parseSCurvePdf with that layout.
        PDF teach     (roof x2 + one-page BUHI) page images + the teach-desk grid ->
                      description letter + month letters -> a JS port of
                      buildTaughtPdfMap -> parseSCurvePdf.
        Excel and PDF-teach are asked twice: "csv" (the grid exactly as the app builds it)
        and "lettered" (each cell tagged with its letter, empties dropped) — to see whether
        comma-counting is what defeats a small model. Prompts carry NO example numbers;
        output is schema-constrained by lm-format-enforcer when it loads (the run records
        whether it did).
      CEILING FIRST (a perfect answer, run through the same scorers) — so a model failure
        means the model, not the path:
        Excel  all 6 PASS exactly. The roof needs header R5 (the DESCRIPTION row); R6, where
               its month labels sit, is refused 'invalid-map' (single-header only). Its
               true months are the hidden-value block AM..AS, not the "30 days" columns.
        PDF labels  roof 2-page and 1-page PASS exactly. The one-page BUHI does NOT read
               through labels even with "Month 1..12" (layout derived, file refuses it) —
               so BUHI is a teach-only case.
        PDF teach   all 3 PASS exactly (roof 2-page F..J + L, M across the page join; BUHI
               M1=H+J … M12=AL+AM).
      FOUND WHILE SELF-TESTING THE SCORER: a layout that names too FEW months (segment,
        M1=I M2=J) passes deriveMapFromAiLayout and reads a wrong 15 / 100 curve. The
        Excel gates prove the columns hold numbers, not that the layout is complete —
        worth remembering if model-taught layouts are ever auto-approved.
      FILES (C:\temp\opencode\testa\): colab_testa_layout.ipynb (source .src.py, built by
        ..\build_ipynb.mjs); colab_inputs\ (12 files: 6 Excel slices, 3 PDF grids, 3 PDFs,
        made by make_inputs.php / make_inputs.mjs from the app's own code);
        score_all.mjs -> score_excel_layout.php, score_pdf_layout.mjs, score_pdf_teach.mjs
        (no argument = the ceiling); probes probe_excel_truth.php, probe_pdf_headers.mjs,
        probe_pdf_blocks.mjs, probe_roof_xlsx.php, dump_slice.php.
      PASS BAR: the curve through the model's layout matches the truth (every month within
        1.0) inside the app's 50 s budget. Results go here when the run is back.
      RUN 1 (user, 2026-09-17) — STOPPED on the first call: CUDA out of memory. BUHI's Excel
        slice is ~8-9k tokens (Qwen tokenizes every digit), and the VL model prefills a
        text prompt in one pass (SDPA asked for 3.92 GiB more). The enforcement fallback
        then retried the same prefill and died too. Notebook rebuilt: Excel now runs on
        the text-only Qwen2.5-7B-Instruct with the 1,024-token chunked prefill that
        carried step 1b's 20k-char sheet; PDF runs afterwards on Qwen2.5-VL-7B (one model
        on the GPU at a time); an out-of-memory call is recorded and the run continues;
        answers are saved after every call and a re-run skips finished ones. Results
        therefore carry a per-run "model".

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

  Decision 4 — The client's document reality deprioritises this whole file (user,
    2026-09-18).
    Context: the PMU confirmed (a) they mainly have the ORIGINAL EXCEL for S-curves and
      (b) their NORMAL documents are mainly SCANNED physical copies.
    Decision: this plan stays paused and drops below pilot-testing readiness. Steps 2
      (configurable endpoint) and 3 (hosting) are NOT ordered.
    Rationale: (a) removes most of the remaining S-curve difficulty — phpspreadsheet
      already reads those workbooks exactly, and §7 was always against putting a model on
      that path. (b) points anywhere an AI tier could still help AWAY from S-curves and
      toward the normal-doc OCR path, which is a different and harder vision problem than
      the clean tables step 1 already failed on.
    Consequences: do not re-open the hosting question on momentum. The live question
      before the pilot is narrower and is tracked elsewhere: what the "Try AI assist"
      button should do while the tier answers ~2% of calls
      (pilot_testing_readiness_plan.md §4 item P5). Step 2 remains cheap and useful
      whenever a provider question returns — it is deferred, not rejected.

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
  3. Step 1 is DONE (2026-09-16) and failed for 3B and 7B on both PDF and Excel — read
     its results in §5 before proposing another model test. To re-run or extend it,
     reuse the notebooks in C:\temp\opencode (colab_vlm_scurve_test / colab_llm_excel_test,
     built from their .src.py by build_ipynb.mjs) and regenerate the Excel prompts with
     dump_excel_prompts.php so they match the app. The fairer small-model test was
     ordered 2026-09-17 and built as STEP 1c (Test A, layout only); a Test B
     (transcribe a scanned PDF's table, our code sums) was proposed and is not ordered.
  4. Keep predictions out of it (§3). The E3 split is what makes this safe.
  5. Log results HERE before committing, and re-check the standing constraints in
     AGENTS.md (blinding stays on; no merge to main without confirmation).

===============================================================================
  END OF PLAN
===============================================================================

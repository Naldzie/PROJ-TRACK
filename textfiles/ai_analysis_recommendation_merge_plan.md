# AI ANALYSIS + RECOMMENDATIONS — ONE PROMPT (✅ DONE — MERGED TO develop 2026-09-21)
# Parent refs: textfiles/ai_recommendation_plan.md (Phase 10 — why the recommendation
#                prompt was added as a SEPARATE Gemini call; its Decision Log 1-10)
#              textfiles/active_reference/AI_MODULE_HANDOVER.md (the AI module map)
#              textfiles/pilot_testing_readiness_plan.md (the active pilot queue)
# Date: September 21, 2026
# Status: 🔄 STEPS 1-4 BUILT + TESTED (2026-09-21) on phase-37-ai-analysis-recs-single-prompt;
#         step 5 (browser check) owed by the user, then the merge question. §8 has the
#         build log. (Was: planned, D1-D5 answered — all five as recommended.)
#         START AT §0 BELOW IF THE SESSION IS COLD, then read the LAST §8 entries.
# Branch on build: phase-37-ai-analysis-recs-single-prompt (Rule 12) — cut from the
#         branch the user names (see §0 "WHERE TO BRANCH FROM").

===============================================================================
  0. HANDOFF — READ THIS FIRST (cold session)
===============================================================================

  WHAT THIS IS, IN ONE PARAGRAPH
    "Regenerate Analysis" makes TWO Gemini calls: one for the 5-section analysis
    (AIExplanationService) and one for the recommendations (AIRecommendationService,
    reached through RecommendationEngine). They never see each other, so the analysis'
    Mitigation bullets and the "Recommended Actions" can repeat or contradict each
    other (visible side by side in the contractor email, sections 02 and 03). The plan
    merges them into ONE call whose prompt is the analysis prompt KEPT VERBATIM plus
    appended recommendation context and a JSON recommendations block; the answer is
    SPLIT before saving so every screen, the email and the PDF keep working unchanged.

  THE USER'S DECISIONS (2026-09-21 — do not re-litigate)
    D1 UI stays SPLIT (Option B): analysis card and Recommendations card stay separate.
    D2 No usable recs in the combined answer → RULE-BASED engine. No second AI call.
    D3 Page views NEVER call Gemini: the presenter uses rule-based recs until an
       analysis has been generated (fixes finding F-2).
    D4 FIX F-1 (the analysis prompt's always-empty FORECAST RELIABILITY block) — as its
       OWN FIRST COMMIT, so its effect on the analysis can be judged on its own.
    D5 Mitigation section stays exactly as it is; the new consistency rule ties the
       recommendations to Possible Risk + Mitigation.

  BUILD ORDER (each a separate commit; run the PHP suite after each; log in §8)
    1. D4 — fix AIExplanationService::buildReliabilityText to read the REAL shape
       (reliability['next_prediction'|'completion'] → level / score / factors keyed
       backtest|data|horizon|agreement — AIRecommendationService::buildReliabilityText
       already does it right; share one implementation) AND fix the test fixture in
       tests/Unit/Services/AI/AIExplanationServiceTest.php (:103 uses the wrong shape
       — that is why the bug never failed a test). §3 F-1.
    2. D3 — AIPredictionPresenter (:694-697): on render, when the latest ai_solutions
       row has no recommendations, use the RULE-BASED path only (no Gemini). Needs a
       rules-only entry on RecommendationEngine (e.g. generateRuleBased()), NOT a
       change to generate()'s AI-first contract used elsewhere.
    3. The combined prompt + one call + split-before-save (§4 steps 1-4), with D2 as
       the fallback and D5's consistency rule.
    4. Tests (§4 step 5) — including a guard that the ORIGINAL analysis prompt text is
       still present verbatim.
    5. Browser check (§4 step 6), log, then ask the user about merging.

  FACTS TO CARRY (verified in source 2026-09-21)
    · AiReportParser (PHP) and parseAnalysisText (JS, ai-analysis.blade.php) read only
      [SECTION:<known key>]…[/SECTION] in a FIXED order and drop everything else.
    · reports/project-summary.blade.php prints analysis_text RAW (nl2br) and the email
      draft falls back to it — so the recommendations block must be CUT OUT of the
      text before saving, never stored inside analysis_text.
    · Use a marker that does NOT start with "[SECTION:" — [RECOMMENDATIONS_JSON] …
      [/RECOMMENDATIONS_JSON] — so neither parser can ever render it.
    · Both calls use the MAIN Gemini key/model (services.gemini.key / .model) with a
      30 s timeout; the combined answer is longer — re-measure before shipping, and
      stay under PHP's 60 s for the regenerate request.
    · The monthly job (GenerateMonthlyAiReports) also goes through
      AIExplanationService::generate() — it inherits the change automatically.

  ✅ RESOLVED 2026-09-21: the user chose "merge 34-36 into develop first". Done; phase-37
     was cut from develop 433a6c5. The block below is the pre-decision record.
  WHERE TO BRANCH FROM (state at 2026-09-21 close)
    · Working branch: phase-36-create-project-modal-ui (contains phase-34 and phase-35).
    · phase-34 / 35 / 36 are BROWSER-CONFIRMED but NOT MERGED to develop and NOT
      PUSHED — the user has not given the merge order yet.
    · develop is 8 commits ahead of origin/develop (the 31-33 merges) — NOT PUSHED.
    · ASK the user before building: merge 34-36 into develop first and branch
      phase-37 from develop (cleanest), or branch from phase-36.

===============================================================================
  1. THE ASK (user, 2026-09-21)
===============================================================================

  "Currently the first AI analysis and the recommendation have separate prompts,
  while the analysis actually has a mitigation section already. So maybe we either
  combine the two prompts and [show] the recommendations on the analysis, or still
  split it on the front end but the prompt is combined. As much as possible retain —
  do not change or remove — the original prompt of the AI analysis while combining
  the prompt for the recommendation."

===============================================================================
  2. HOW IT WORKS TODAY — traced in source 2026-09-21
===============================================================================

  2.1 TWO GEMINI CALLS PER "REGENERATE ANALYSIS"
    ProjectController::regenerateAnalysis()          (POST …/regenerate-analysis)
      → AIExplanationService::generate()
          1. AIPredictionService::generatePrediction()          (math, no AI)
          2. buildPrompt() → callGemini()          ◀ GEMINI CALL #1 — the analysis
          3. RecommendationEngine::generate()
               → AIRecommendationService::generate()
                   buildPrompt() → callGemini()  ◀ GEMINI CALL #2 — the recs
               → on an empty/invalid answer: the rule-based engine (unchanged)
          4. saveAnalysis(): ONE ai_solutions row
               analysis_text   = the 5-section text        (call #1)
               recommendations = [{message, category, reason, priority,
                                   ai_generated}]           (call #2 or rules)
    The same two calls run per project in the monthly job
    (GenerateMonthlyAiReports → AIExplanationService::generate()).
    Both calls use the MAIN key/model (services.gemini.key / .model), 30 s timeout
    each, and fail silently to '' / [].

  2.2 THE TWO PROMPTS
    ANALYSIS  (AIExplanationService::buildPrompt, :138) — "project management
      analyst", data: overview, regression, FORECAST RELIABILITY, prediction-accuracy
      history, risk + trend, 3-method completion estimates, 5 most recent issues.
      Output: EXACTLY 5 marked sections —
        [SECTION:Report] [SECTION:Possible Risk] [SECTION:Mitigation]
        [SECTION:Performance] [SECTION:AI Comment]
      Mitigation is "bullet points: areas requiring closer monitoring or preventive
      measures". Audience: the CONTRACTOR.
    RECOMMENDATIONS  (AIRecommendationService::buildPrompt, :208) — "construction
      project management analyst", 3-6 prioritized actionable recs, each must cite
      project data. Data the analysis prompt does NOT have: expected progress + gap,
      days remaining, category, contractor performance, open-issue breakdown (type /
      severity / aging / resolved-30d + meaningful details), approved extensions,
      latest 3 inspection remarks, class standing, carryovers. Output: ONLY a JSON
      array of {message, category, reason, priority}.

  2.3 WHERE EACH OUTPUT IS SHOWN
    analysis_text   → AI Prediction tab "AI Analysis & Recommendations" card
                      (AiReportParser::parseToHtml; the tab also has a JS twin
                      parseAnalysisText for the same markers)
                    → contractor email, section "02 · AI-Generated Analysis"
                    → Project Summary PDF (printed RAW with nl2br — markers and all)
                    → email draft review (tracking->body falls back to it)
    recommendations → AI Prediction tab green "Recommendations" card (category
                      badge, purple "AI" badge, reason)
                    → contractor email, section "03 · Recommended Actions"
                      (top 5 by priority)
    ⇒ THE OVERLAP THE USER NOTICED IS REAL AND VISIBLE: the email shows the
      Mitigation bullets inside section 02 AND "Recommended Actions" in 03, written
      by two independent model calls that never saw each other's answer — they can
      repeat or contradict each other.

  2.4 PARSER FACTS THAT DECIDE THE DESIGN
    · AiReportParser::parse() (and the tab's JS twin) read only
      [SECTION:<known key>] … [/SECTION], IN THIS FIXED ORDER: Report, Possible Risk,
      Mitigation, Performance, AI Comment, Next Steps, Progress. Anything outside the
      markers is DROPPED; an unknown key is ignored.
    · BUT the PDF prints analysis_text RAW and the email draft copies it — so a
      recommendations block must NEVER be stored inside analysis_text. It has to be
      cut out before saving (see §4, step 3).

===============================================================================
  3. FINDINGS THE STUDY SURFACED (independent of the merge)
===============================================================================

  F-1 ⚠ THE ANALYSIS PROMPT NEVER RECEIVES THE RELIABILITY SCORES. (a real bug)
      ForecastReliabilityService returns reliability['next_prediction'] and
      ['completion'], each {level, score, factors: {backtest, data, horizon,
      agreement}}. AIRecommendationService reads exactly that — correct.
      AIExplanationService::buildReliabilityText (:280) reads 'next_reliability',
      'completion_reliability', 'label' and a factors LIST with 'key' ⇒ none of it
      exists, so for every project WITH data the analysis prompt gets a bare
      "FORECAST RELIABILITY:" heading and nothing under it — while the same prompt
      tells the model "Use the FORECAST RELIABILITY score to assess prediction trust
      instead". HIDDEN BY ITS OWN TEST: AIExplanationServiceTest builds its fixture in
      the same wrong shape (:103 'next_reliability' => [... 'label' ...]).
      Fixing it changes only the DATA lines fed into the prompt, not one word of the
      prompt's instructions — but it is a behaviour change, so it is decision D4.

  F-2 A PAGE VIEW CAN CALL GEMINI. AIPredictionPresenter (:694-697): when the latest
      ai_solutions row has no recommendations (or no row exists — a project nobody has
      pressed Regenerate on), rendering the AI Prediction tab calls
      RecommendationEngine::generate(), which is AI-FIRST — up to a 30 s Gemini call
      inside a page load, repeated on every view until someone regenerates. Decision D3.

  F-3 The recommendation prompt's data is richer than the analysis prompt's. A combined
      prompt must carry the recommendation data too, or recommendation quality drops.

===============================================================================
  4. PROPOSED DESIGN — ONE PROMPT, TWO OUTPUTS (recommended: "Option B")
===============================================================================

  PRINCIPLE: the analysis prompt's text stays VERBATIM — its opening instruction, its
  data blocks, its R² warning, its 5-section OUTPUT FORMAT and its closing
  "Do NOT mention that you are an AI…" paragraph. Everything new is APPENDED.

  STEP 1 — BUILD THE COMBINED PROMPT  (AIExplanationService)
    [A] the current analysis prompt, unchanged.
    [B] APPENDED data block "ADDITIONAL PROJECT CONTEXT (for the recommendations)":
        the blocks only the recommendation prompt had (§2.2) — expected progress +
        gap, days remaining, category, contractor performance, ISSUES breakdown,
        EXTENSIONS, RECENT INSPECTIONS, CROSS-PROJECT CONTEXT. Reuse the existing
        builders in AIRecommendationService (make them callable, e.g. a small
        public buildRecommendationContext()) — do NOT copy them (Rule 6/7).
    [C] APPENDED output part, after the 5 sections:
          "After the 5 sections, add the recommendations between these exact markers:
           [RECOMMENDATIONS_JSON] … [/RECOMMENDATIONS_JSON]"
        followed by the recommendation prompt's OUTPUT FORMAT (the JSON shape) and
        its RULES list, copied verbatim, plus ONE new rule that is the point of
        combining:
          "The recommendations must be consistent with your Possible Risk and
           Mitigation sections — each should turn a risk or mitigation point into a
           concrete action. Do not contradict or merely repeat those sections."
        The marker deliberately does NOT start with [SECTION: — so neither parser
        (PHP nor the tab's JS) can ever render it as a section, even if it leaks.

  STEP 2 — ONE GEMINI CALL
    Same callGemini(). The answer is longer (5 sections + JSON), so re-measure the
    30 s timeout on a real project before shipping (E6 lesson from the parser track:
    a timeout fitted to a smaller payload kills good answers); raising it must stay
    under PHP's 60 s limit for the regenerate request.

  STEP 3 — SPLIT THE ANSWER BEFORE SAVING
    · analysis_text   = the answer with the [RECOMMENDATIONS_JSON] block CUT OUT —
      byte-for-byte the same format stored today, so the tab, the email, the PDF and
      every existing row keep working untouched.
    · recommendations = the block's JSON, run through AIRecommendationService's
      EXISTING parseResponse() (code-fence stripping) and validateRecommendation()
      (+ priority clamp, lower-case category, ai_generated = true, sort, cap 6) —
      reuse, not copy.
    · If the block is missing / malformed / yields 0 valid recs → decision D2
      (recommended: the RULE-BASED engine, no second AI call).

  STEP 4 — WIRING
    · AIExplanationService::generate() stops calling RecommendationEngine for the AI
      path; it uses the recs from its own answer, or the D2 fallback.
    · AIRecommendationService stays in the codebase (its standalone prompt is still
      the path for anything else that asks RecommendationEngine for AI recs — see D3),
      so nothing is deleted in this change.
    · Front end: NO CHANGE under Option B — the analysis card and the green
      Recommendations card stay separate; the email keeps 02 + 03.

  STEP 5 — TESTS (the prompts are pinned by tests — update deliberately)
    · AIExplanationServiceTest — assert the ORIGINAL prompt text is still present
      verbatim (a guard against the "retain the original prompt" rule being broken
      later), the appended context and the JSON part are present, and (if D4) the
      reliability fixture uses the REAL shape.
    · New unit tests for the splitter: block present → both outputs; block absent →
      analysis saved + fallback recs; malformed JSON; code fences; a block placed
      mid-text; analysis_text never contains the marker.
    · AIIntegrationTest / AIRecommendationIntegrationTest / AiReviewWindowTest /
      AIPredictionTabRenderTest — any Http::fake sequence expecting TWO Gemini calls
      per regenerate now expects ONE.
    · Falsify each new rule by mutation (project habit).

  STEP 6 — LOG + BROWSER CHECK
    Regenerate on a real project; compare the Mitigation bullets with the
    recommendations (consistent, not duplicated); check the tab, the email draft and
    the PDF show no JSON.

===============================================================================
  5. THE TWO FRONT-END OPTIONS
===============================================================================

  OPTION B — COMBINED PROMPT, UI STAYS SPLIT  (recommended)
    + No UI, email or PDF change; stored format unchanged; old rows unaffected.
    + Recommendations keep their structure (category badge, priority, reason, the AI
      badge, the email's P1/P2 pills) and the rule fallback keeps its exact slot.
    + Gains: one Gemini call instead of two (regenerate AND the monthly job), and the
      recommendations are written in the same reasoning pass as Mitigation, so they
      stop contradicting it.
  OPTION A — COMBINED PROMPT AND RECOMMENDATIONS SHOWN INSIDE THE ANALYSIS
    Everything in B, plus: render the recommendations as a 6th block in the analysis
    card (after Mitigation) instead of the separate green card.
    − Touches three renderers (the tab card, the email's 02/03 sections, the PDF),
      and the rule-based fallback recs would then appear inside an "AI analysis",
      which misstates where they came from.
    Can be done LATER on top of B without redoing anything — B is a strict subset.

===============================================================================
  6. DECISIONS FOR THE USER (before any build) — ✅ ALL ANSWERED 2026-09-21
     User: "d1 split as recommended · d2 go with the recommendation · d3 yes ·
     d4 yes · d5 yes". Final build order is in §0.
===============================================================================

  D1  Option B (UI stays split) or Option A (recs inside the analysis)?
      Recommendation: B now; A later if wanted.
  D2  When the combined answer has no usable recommendations: rule-based engine, or a
      second Gemini call with the standalone recommendation prompt?
      Recommendation: rule-based — deterministic, free, and exactly today's fallback.
  D3  Page views (F-2): keep the AI-first call on render, or use rule-based recs on
      render until an analysis has been generated?
      Recommendation: rules on render — a page load should never wait on Gemini.
  D4  Fix F-1 (the analysis prompt's empty reliability block) as part of this work?
      It changes the prompt's DATA, not its wording. Recommendation: yes, as its own
      first commit, so its effect on the analysis can be judged separately.
  D5  Keep the Mitigation section exactly as it is (recommended — the ask is to retain
      the analysis prompt), with the new consistency rule tying the recs to it?

===============================================================================
  7. FILES THIS WOULD TOUCH (when ordered)
===============================================================================

  app/Services/AI/AIExplanationService.php     prompt append, split, save
  app/Services/AI/AIRecommendationService.php  expose context + parse/validate helpers
  app/Services/AI/RecommendationEngine.php     only if D3 (render path)
  app/Services/AI/AIPredictionPresenter.php    only if D3
  tests/Unit/Services/AI/AIExplanationServiceTest.php, AIRecommendationServiceTest.php,
  tests/Feature/Services/AI/*Integration*Test.php, AiReviewWindowTest, AIPredictionTabRenderTest
  textfiles/ai_recommendation_plan.md          cross-reference + decision log entry
  NOT touched under Option B: every Blade view, AiReportParser, the email template, the PDF.

===============================================================================
  8. SESSION LOG
===============================================================================

  2026-09-21 — Study done read-only; this plan written. No code changed.
  2026-09-21 — User answered D1-D5, all as recommended (§6). Plan turned into a build
    order with a cold-session handoff (§0). Docs updated for handoff: AGENTS.md,
    ai_recommendation_plan.md, AI_MODULE_HANDOVER.md, pilot_testing_readiness_plan.md.
    STILL NO CODE CHANGED — the build waits for the user's "go".
  2026-09-21 — "GO" given. User order: merge 34-36 into develop first, then branch.
    Done: phase-34 → 35 → 36 merged into develop (--no-ff, one merge commit each, no
    conflicts; NOT pushed). Branch phase-37-ai-analysis-recs-single-prompt cut from
    develop 433a6c5.
  2026-09-21 — STEP 1 (D4 / F-1) ✅ BUILT.
    · New app/Services/AI/ReliabilityPromptText.php — ONE renderer for the reliability
      block, reading the REAL shape (reliability['next_prediction'|'completion'] →
      level / score / factors[backtest|data|horizon|agreement]). Both services' private
      buildReliabilityText() now delegate to it; each passes its own heading, so the
      analysis prompt still says "FORECAST RELIABILITY:" and the recommendation prompt
      keeps "FORECAST RELIABILITY (use this, NOT R², to assess prediction trust):".
    · One small deliberate change beyond the port: when BOTH results are null (the
      report's default shape for too little data) the block now says "N/A —
      insufficient data…" instead of a bare heading. Before, this happened in both
      prompts (the recommendation copy too).
    · Measured on the live DB, 8 projects, "now" frozen: all 8 RECOMMENDATION prompts
      are byte-identical before/after; all 8 ANALYSIS prompts differ ONLY by the added
      reliability data lines (project 7: Next-month Medium 78, Completion Medium 77,
      four factor lines). No instruction wording changed.
    · Test fixture fixed to the real shape (AIExplanationServiceTest), +3 tests (both-null
      N/A; factors follow next-month; factors fall back to completion). Mutation: the old
      buildReliabilityText put back → 4 tests red.
    · PHP suite 1259 passed / 0 failed.
  2026-09-21 — STEP 2 (D3 / F-2) ✅ BUILT.
    · RecommendationEngine: generate() and the new generateRuleBased() share one private
      build(project, prediction, ?aiSource). The AI source is a closure, so generate()
      still calls Gemini only AFTER the guards (completed / 0 obs / few obs) — same order
      as before; generate()'s AI-first contract is unchanged.
    · AIPredictionPresenter (the "Recommendations (saved or fresh)" block) now calls
      generateRuleBased() — a page view can no longer wait on Gemini. Stored recs are
      still preferred exactly as before.
    · Side effect worth knowing: ProjectController::regenerateAnalysis() falls back to
      the presenter when the analysis call fails; that fallback used to make a SECOND
      Gemini call (for recs) right after the first had failed. It no longer does.
    · New tests/Feature/Services/AI/AIAnalysisSinglePromptTest.php (Http::fake +
      preventStrayRequests): positive control (generate() does send 1 call), rules-only
      sends nothing and equals the no-AI engine's output, container-built presenter
      render sends nothing. Mutation: presenter back on generate() → render test red.
    · PHP suite 1262 passed / 0 failed.
  2026-09-21 — STEPS 3 + 4 (combined prompt, one call, split, tests) ✅ BUILT.
    Committed together — the tests are the proof of the code, not a later phase.
    · AIRecommendationService: context computation moved to private contextParts();
      new PUBLIC buildRecommendationContext() (the F-3 data blocks), public constants
      RECOMMENDATION_JSON_SHAPE + RECOMMENDATION_RULES (shared by both prompts), new
      PUBLIC parseRecommendations() (parse + validate + clamp + tag + sort + cap, lifted
      out of generate()). The standalone prompt is BYTE-IDENTICAL on 8 live projects.
    · RecommendationEngine: + generateFromAiCandidates() (guards, then the caller's AI
      recs, else rules — never a second call, D2) and + acceptsAiRecommendations() (true
      when not Completed and obs > ai.recommendations.min_observations — exactly when
      build() reaches the AI step).
    · AIExplanationService: ONE call. buildPrompt(…, $withRecommendations) returns the
      original prompt unchanged and, when the engine would use AI recs, APPENDS
      buildRecommendationsAppendix(): the extra data, the shared JSON shape + rules, the
      D5 consistency rule, and the markers [RECOMMENDATIONS_JSON] … [/RECOMMENDATIONS_JSON]
      (constants RECS_OPEN / RECS_CLOSE). The standalone rule "Return ONLY the JSON array,
      no other text" is NOT carried over (it contradicts the 5 sections); replaced by
      "nothing but the JSON array between the markers, nothing after the close".
      When the engine would not use AI recs (Completed / too few observations) the prompt
      is exactly the original analysis prompt.
    · splitCombinedAnswer(): cuts every block (plus a code fence wrapped around it) out of
      the answer; an unclosed block takes the rest of the answer; with NO markers, a JSON
      array after the last [/SECTION] is treated as the block (so raw JSON can never reach
      analysis_text, which the PDF prints raw); prose after the sections is left alone.
      An answer with nothing but a block → '' → the controller's static fallback.
    · callGemini() now logs one line per call ("AI analysis: Gemini call": ms, status,
      model, prompt/answer chars — never the URL, which carries the key); generate() logs
      "AI analysis: recommendations resolved" (asked / block_found / ai_recs / source).
    · Tests: AIAnalysisSinglePromptTest now 20 tests — verbatim-prefix guard + the four
      original instruction passages; appendix contents; 8 splitter cases; one-call end-to-
      end (row has no marker, recs ai_generated); no block → rules; malformed JSON and
      zero-valid recs → rules; young project → no appendix in the sent prompt and the
      data-focused recs saved; 503 → nothing saved, 1 call; block-only answer → nothing
      saved; POST admin.projects.regenerate-analysis → exactly 1 Gemini call.
    · Mutations (each restored): ignore the block → 2 red; do not cut → 10 red; never
      append → 3 red; second AI call for recs → 3 red; drop the consistency rule → 1 red;
      drop the unmarked-JSON fallback → 1 red.
    · PHP suite 1279 passed / 0 failed. JS untouched (no JS changed).
    · LIVE MEASUREMENT (one call, project 7, no DB write): model gemini-flash-lite-latest,
      6.6 s, prompt 5 681 chars, answer 6 145 chars; block found, 4 valid recs, analysis
      clean. Well inside the 30 s timeout — left at 30 s. The recommendations turned the
      Mitigation bullets into concrete prioritized actions (supplier sourcing, re-
      sequencing, weekly review, extension paperwork) — consistent, not duplicated.
    · ⚠ SIDE EFFECT TO RAISE WITH THE USER: the analysis half now SEES the appended data
      too, so its Possible Risk section cited the contractor's rating (1.00/5), on-time
      20%, avg delay and the 4 extensions. The analysis is written for the CONTRACTOR
      (email section 02), so this is new contractor-visible content. The prompt text is
      unchanged; it is the data that is new.
    · Known data gap carried over unchanged: EXTENSIONS "Reasons: : 4" when
      reason_category is empty (was already in the standalone recommendation prompt).
    · ⬜ STEP 5 — browser check owed (user): Regenerate on a real project; tab analysis
      card + green Recommendations card (purple AI badges); email draft and Project
      Summary PDF show no JSON / no marker; laravel.log shows ONE "AI analysis: Gemini
      call" line per Regenerate.
  2026-09-21 — STEP 5, USER RUN on project 142 (PRJ-2026-00037). Compared ai_solutions
    #33 (2026-09-14, old code), #34 (2026-09-21 16:00, old code, same data) and #35
    (16:30:49, new code), plus the Project Summary PDF generated from #35.
    · LOG ✅: exactly ONE "AI analysis: Gemini call" for the Regenerate (200, 6 782 ms,
      prompt 5 839, answer 6 539 chars) + "recommendations resolved": asked, block found,
      4 AI recs, source ai. (The 16:20:39 project-7 line is the measurement script — it
      calls callGemini only, so it has no "resolved" line.)
    · PDF ✅ for this change: no JSON and no [RECOMMENDATIONS_JSON] marker anywhere; the
      AI RECOMMENDATIONS table shows the 4 recs from #35.
    · CONSISTENCY ✅ (the point of the merge): #34 shows the old contradiction — its
      analysis calls the project "stable … low overall risk", while the separate recs call
      open with "Immediately intervene … chronic schedule slippage" at PRIORITY 1. In #35
      each rec corresponds to a Mitigation bullet (material log → rec 1; contractor
      supervision → rec 2; bi-weekly reviews to close the 2% gap → rec 3; near-term
      forecast → rec 4), and the analysis itself now names the contractor record.
    · F-1 VISIBLE ✅: #33/#34 never quote a reliability score (#34 even calls confidence
      "high across the individual methodologies"); #35 cites 75/100, 84/100, 85/100 and
      method agreement 60/100.
    · BONUS: #33/#34 quoted the gibberish issue name "asadsadsasdsadadasds1123" in Risk
      AND Mitigation; #35 does not — the appended gibberish-text rule carried over to the
      analysis half.
    · ⚠ SIDE EFFECT CONFIRMED (question open for the user): #35's contractor-facing
      analysis cites the contractor's 1.00/5 rating, 20.00% on-time rate and 7.20-day
      average delay (Report, Possible Risk, Mitigation, Performance) and the class
      standing ("above the 75th percentile of similar projects").
    · Minor: #35's Report paragraph 2 recites the reliability factor scores (horizon
      100/100, history 84/100) — more technical for a contractor audience; a consequence
      of F-1 (the data now arrives), not of the merge.
    · PRE-EXISTING, NOT FROM THIS CHANGE (found while reading the PDF):
      (a) the PDF's AI ANALYSIS box prints the raw [SECTION:…] / [/SECTION] markers —
          reports/project-summary.blade.php prints analysis_text with nl2br (§2.3).
      (b) every project-summary PDF since 2026-08-05 starts with a UTF-8 BOM (EF BB BF)
          before %PDF-, so strict readers reject it (browsers tolerate it). Source:
          bootstrap/app.php is saved WITH a BOM, so every response body gets those 3
          bytes. Also BOM-saved: resources/views/auth/login.blade.php,
          projects/project_detail/components/modals/issues_modal.blade.php.
      Neither fixed — both need an order.
    · Still to confirm by the user: the email draft (review window) shows no JSON.
  2026-09-21 — USER: email draft ✅ CONFIRMED (no JSON). PDF items (a) + (b) above are
    PARKED by the user for a later PDF improvement pass ("include this fix later") — do
    them together with that work. User order: "make the ai analysis not technical".
  2026-09-21 — PLAIN-LANGUAGE STYLE BLOCK ✅ BUILT.
    · AIExplanationService::buildPlainLanguageAppendix(), appended right AFTER the
      original closing paragraph, in BOTH modes (with or without the recommendations
      appendix, so young/completed projects and the monthly job get it too), and BEFORE
      the recommendations appendix. The original analysis prompt text is still untouched.
    · Content: plain language for a contractor/site engineer; USE the technical inputs
      (reliability + factor scores, R², method/equation, method agreement, horizon,
      accuracy %, class standing) to decide what to say, but do NOT quote scores,
      percentiles or terms; example translations given; keep concrete facts (progress %,
      dates, days, issue names/counts, extension days). Scope: the 5 sections only — the
      recommendation JSON/rules are unchanged.
    · Live, project 142, no DB write (3 calls, 5.2-5.4 s each):
      run 1 (without the percentile example) — no /100 scores, no factor names, but
      "ranks above the 75th percentile" twice (the class-standing label is a percentile).
      Added the example "progress is ahead of most similar projects (never write the word
      percentile or a percentile number)". Runs 2 + 3 — none of percentile, /100, R²,
      polynomial, regression, method agreement, horizon, reliability score, equation in
      the sections. Reliability now reads e.g. "past forecasts have generally been close
      to what was actually achieved"; the finish-date spread as "disagree by about three
      months".
    · Contractor stats (1.00/5, 20% on-time, 7.2-day delay) still appear in plain words in
      the analysis — that question (§ side effect above) is still OPEN with the user.
    · Test: test_plain_language_block_is_appended_in_both_modes (closing paragraph
      immediately followed by the block, both modes; block before RECS_OPEN). Mutation:
      block removed → 1 red. PHP suite 1280 passed / 0 failed.
  2026-09-21 — USER: contractor stats in the analysis → "leave it as is" (decided).
    User browser-tested the plain-language version (row #36). USER CONCERN: before the
    merge the recommendations covered every category; after it they are mostly
    corrective / monitoring. Is that expected?
    · MEASURED on project 142, every ai_solutions row: #30-#34 (old, two calls) — ALL
      FOUR categories in every row; #35 corr/moni only; #36 corr/moni only and just 3
      recs, with the contractor's record listed in Possible Risk but no rec acting on it
      and "Increase weekly output" labelled monitoring.
    · CAUSE (mine, not the merge as such): the consistency rule said "each should turn a
      risk or mitigation point into a concrete action", and the ORIGINAL prompt defines
      Mitigation as "closer monitoring or preventive measures" — so every rec inherited
      that scope. Escalation (a PMU decision / formal notice) and administrative
      (records, extensions) are PMU-side actions with no Mitigation bullet to hang on.
      Neither prompt, old or new, ever DEFINED the four categories. Shows most on
      low-risk projects (project 7, Critical, still got all four).
    · TRIAL (in-memory rule swap, no code change): candidate wording gave all four
      categories on p142 × 2 and p7 × 2.
  2026-09-21 — CATEGORY RULE ✅ BUILT (user: "yes lets try it").
    · Recommendations appendix only (original analysis prompt and standalone
      recommendation prompt untouched): the consistency rule is now "the recs are for
      the PMU; consistent with Possible Risk + Mitigation — never contradict or merely
      repeat — and every Possible Risk point gets at least one rec"; + one-line
      definitions of escalation / corrective / monitoring / administrative; + "include
      escalation or administrative actions whenever the data warrants them; do not add a
      category just to fill it"; + "write numbers as digits" (one plain-language run had
      spelled out "one hundred ninety days").
    · Plain-language block: + "call the model simply 'the forecast' — not 'the regression
      model' or its method name" (two runs had said "the regression model"; the ORIGINAL
      Performance instruction asks about "regression model reliability", which stays).
    · LIVE (real code, no DB write), 9 runs: categories p142 esca1 corr2 moni3 ·
      esca1 moni2 admi2 corr2 · esca2 moni2 admi3 · esca1 corr2 moni2 admi3; p7 esca1 moni1
      corr2 admi3 · esca1 corr1 moni2 admi3 — escalation in every run, administrative in
      most, contractor record now ACTED ON (formal reminder / recovery schedule), labels fit.
      Numbers as digits in every run. 5.0-6.7 s per call.
    · Residual: "75th percentile" still appeared in the analysis in 1 of the last 4 runs —
      the model copies the class-standing DATA line ("Above 75th percentile …"). A
      deterministic fix would reword that data line; not done (it is shared with the
      standalone prompt) — raised with the user.
    · Tests: appendix test now pins the risk-coverage rule, the four definitions, the
      "whenever the data warrants / not to fill" line, the digits rule, and that the old
      narrowing wording is gone. Mutation: old wording restored → 1 red.
      PHP suite 1280 passed / 0 failed.
  2026-09-21 — USER: the "75th percentile" residual → LEAVE AS IS for now (noted, not
    fixed). If it is picked up later: reword the class-standing DATA line built in
    AIRecommendationService::contextParts() (e.g. "ahead of most similar projects"
    instead of the tier label "Above 75th percentile") — deterministic, but it also
    changes the standalone recommendation prompt, so decide that knowingly.
    User order: merge phase-37 into develop.
  2026-09-21 — ✅ MERGED phase-37 into develop (6e3ba20, --no-ff, no conflicts). On
    develop: PHP 1280 passed / 0 failed, JS 267 / 267 / 0. NOT pushed. Plan CLOSED.
    Open items carried elsewhere (AGENTS.md Known Issues): the "75th percentile"
    residual (left as is) and the two summary-PDF defects (parked for the PDF pass).

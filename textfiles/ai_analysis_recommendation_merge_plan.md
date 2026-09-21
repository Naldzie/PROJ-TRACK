# AI ANALYSIS + RECOMMENDATIONS — ONE PROMPT (PLAN, NOT BUILT)
# Parent refs: textfiles/ai_recommendation_plan.md (Phase 10 — why the recommendation
#                prompt was added as a SEPARATE Gemini call; its Decision Log 1-10)
#              textfiles/active_reference/AI_MODULE_HANDOVER.md (the AI module map)
#              textfiles/pilot_testing_readiness_plan.md (the active pilot queue)
# Date: September 21, 2026
# Status: ⬜ PLAN ONLY — studied at the user's request ("study … and make a plan. no
#         implementation for now"). Nothing below is built. Every step needs an order
#         (Rule 10), and §6 lists the decisions that are the user's to make first.
# Branch on build: phase-<n>-ai-analysis-recs-single-prompt (Rule 12).

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
  6. DECISIONS FOR THE USER (before any build)
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

<?php

namespace App\Services\AI;

use App\Models\PredictionHistory;
use App\Models\Project;
use App\Models\AiSolution;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================================
 * AIExplanationService
 * ============================================================================
 *
 * Purpose
 * -------
 * Generates natural-language analysis text using an external AI API,
 * replacing static template-based analysis text.
 *
 * The service uses Google Gemini (configurable) and stores each generated
 * analysis in the ai_solutions table with a regression snapshot so the
 * analysis can be traced back to the exact data that produced it.
 *
 * Architecture
 * ------------
 * This service is provider-agnostic — swapping to OpenAI or a local model
 * later requires changing only the callGemini() method.
 *
 * Fallback
 * --------
 * If the API call fails (timeout, network error, invalid key, empty response),
 * the method returns an empty string. The caller (AIPredictionPresenter)
 * falls back to the static generateAnalysisText() template.
 *
 * Used By
 * -------
 * ProjectController::regenerateAnalysis() (Phase 6.3)
 *
 * One Call, Two Outputs (2026-09-21)
 * ----------------------------------
 * The same Gemini call also writes the recommendations, as a JSON block
 * between [RECOMMENDATIONS_JSON] markers appended after the 5 sections.
 * The block is cut out before saving: analysis_text keeps its old format,
 * recommendations go to their own column. No usable block → rule-based
 * recommendations. Plan: textfiles/ai_analysis_recommendation_merge_plan.md
 *
 * Depends On
 * ----------
 * AIPredictionService     — provides the regression prediction data used to
 *                           build the AI prompt
 * AIRecommendationService — recommendation context, JSON shape/rules, and
 *                           the parse/validate step for the block
 * RecommendationEngine    — guards + rule-based fallback recommendations
 * AiSolution model        — persists generated analyses
 * config/services.php     — Gemini API key and model name
 */
class AIExplanationService
{
    private AIPredictionService $aiPredictionService;

    private RecommendationEngine $recommendationEngine;

    private AIRecommendationService $aiRecommendationService;

    /**
     * Opening marker of the recommendations block in the combined answer.
     * Deliberately NOT "[SECTION:…" — AiReportParser and the tab's JS twin
     * read only [SECTION:<known key>], so even a leaked block never renders.
     */
    public const RECS_OPEN = '[RECOMMENDATIONS_JSON]';

    /**
     * Closing marker of the recommendations block.
     */
    public const RECS_CLOSE = '[/RECOMMENDATIONS_JSON]';

    /**
     * ------------------------------------------------------------------------
     * Constructor
     * ------------------------------------------------------------------------
     *
     * Inject AIPredictionService to retrieve regression data for the prompt.
     * Inject RecommendationEngine for the guards + rule-based fallback.
     * Inject AIRecommendationService for the recommendation context blocks
     * and the JSON parse/validate step of the combined answer.
     *
     * @param  RecommendationEngine|null     $recommendationEngine     Optional; auto-resolved by container
     * @param  AIRecommendationService|null  $aiRecommendationService  Optional; auto-resolved by container
     */
    public function __construct(
        AIPredictionService $aiPredictionService,
        ?RecommendationEngine $recommendationEngine = null,
        ?AIRecommendationService $aiRecommendationService = null
    ) {
        $this->aiPredictionService = $aiPredictionService;
        $this->recommendationEngine = $recommendationEngine ?? new RecommendationEngine();
        $this->aiRecommendationService = $aiRecommendationService ?? new AIRecommendationService();
    }

    /**
     * ------------------------------------------------------------------------
     * Get Latest AI Solution
     * ------------------------------------------------------------------------
     *
     * Returns the most recent AiSolution record for a project, or null
     * if none exists.
     *
     * Called by: AIPredictionPresenter::presentNormal() (Phase 6.4)
     * Uses: AiSolution model
     */
    public function getLatest(Project $project): ?AiSolution
    {
        return $project->aiSolutions()->first();
    }

    /**
     * ------------------------------------------------------------------------
     * Generate AI Analysis Text
     * ------------------------------------------------------------------------
     *
     * Entry point for generating AI-powered analysis text.
     *
     * Workflow
     * --------
     * 1. Run the prediction pipeline via AIPredictionService
     * 2. Build the prompt: the analysis prompt (verbatim) plus, when the
     *    engine would use them, an appended recommendations request
     * 3. Call the Gemini API ONCE
     * 4. Split the answer: analysis text + [RECOMMENDATIONS_JSON] block
     * 5. Recommendations: the block's validated recs, else rule-based
     * 6. On success → save to ai_solutions table, return the analysis text
     * 7. On failure → return empty string (triggers static fallback)
     *
     * Until 2026-09-21 this made TWO Gemini calls (analysis, then
     * RecommendationEngine::generate() for recs) that never saw each other's
     * answer, so Mitigation and the recommendations could repeat or
     * contradict each other. See ai_analysis_recommendation_merge_plan.md.
     *
     * Called by: ProjectController::regenerateAnalysis() (Phase 6.3),
     *            GenerateMonthlyAiReports
     * Uses: AIPredictionService, RecommendationEngine, AIRecommendationService,
     *       buildPrompt(), callGemini(), splitCombinedAnswer(), saveAnalysis()
     */
    public function generate(Project $project): string
    {
        // Run prediction pipeline — catch insufficient-data case
        try {
            $prediction = $this->aiPredictionService->generatePrediction($project);
        } catch (\InvalidArgumentException $e) {
            return '';
        }

        // Ask for recommendations in the same call only when the engine would
        // actually use them (not Completed, enough observations). Otherwise
        // the prompt is exactly the original analysis prompt.
        $withRecommendations = $this->recommendationEngine->acceptsAiRecommendations($project, $prediction);

        $prompt = $this->buildPrompt($project, $prediction, $withRecommendations);
        $raw = $this->callGemini($prompt, $project->id);

        if (empty($raw)) {
            return '';
        }

        // ONE answer, TWO outputs: cut the recommendations block out so
        // analysis_text is stored in exactly the format it always was.
        $split = $this->splitCombinedAnswer($raw);
        $text = $split['analysis'];

        if ($text === '') {
            return '';
        }

        // Recommendations from the same answer; none usable → rule-based
        // engine, never a second Gemini call (decision D2).
        $aiRecs = ($withRecommendations && $split['recommendations'] !== null)
            ? $this->aiRecommendationService->parseRecommendations($split['recommendations'])
            : [];

        $recommendations = $this->recommendationEngine->generateFromAiCandidates($project, $prediction, $aiRecs);

        Log::info('AI analysis: recommendations resolved', [
            'project_id'    => $project->id,
            'asked'         => $withRecommendations,
            'block_found'   => $split['recommendations'] !== null,
            'ai_recs'       => count($aiRecs),
            'source'        => !empty($aiRecs) ? 'ai' : 'rules',
            'saved_count'   => count($recommendations),
        ]);

        $this->saveAnalysis($project, $text, $prediction, $recommendations);

        return $text;
    }

    /**
     * ------------------------------------------------------------------------
     * Build AI Prompt
     * ------------------------------------------------------------------------
     *
     * Constructs a structured prompt with project context and regression data
     * for the AI to analyze.
     *
     * The analysis prompt below is kept VERBATIM (user rule, 2026-09-21: "do
     * not change or remove the original prompt"). With $withRecommendations
     * the recommendations request is APPENDED after it — see
     * buildRecommendationsAppendix(). A test pins that the prompt without the
     * appendix is a byte-exact prefix of the prompt with it.
     *
     * Uses: Project model, AIPredictionService output
     */
    private function buildPrompt(Project $project, array $prediction, bool $withRecommendations = false): string
    {
        $currentState   = $prediction['current_state'];
        $nextPrediction = $prediction['next_prediction'];
        $regression     = $prediction['regression'];
        $statistics     = $prediction['statistics'];
        $assessment     = $prediction['assessment'];
        $completionMulti = $prediction['completion_multi'] ?? [];
        $reliability     = $prediction['reliability'] ?? null;

        $currentProgress = $currentState['progress'] ?? 0;
        $rSquared        = $regression['r_squared'] ?? 0;
        $fitQuality      = $regression['fit_quality'] ?? 'Unknown';
        $obsCount        = $statistics['observation_count'] ?? 0;
        $nextProgress    = $nextPrediction['progress'] ?? 0;
        $regMethod       = $regression['regression_method'] ?? 'slr';
        $regEquation     = $regression['equation'] ?? 'N/A';
        $methodReason    = $regression['method_reason'] ?? '';

        // Forecast reliability (Phase 14) — weighted, explainable trust signal
        $reliabilityText = $this->buildReliabilityText($reliability);

        // Prediction history accuracy (Phase 7)
        $historyAccuracy = '';
        $historyRows = PredictionHistory::where('project_id', $project->id)
            ->whereNotNull('actual_next')
            ->latest('created_at')
            ->take(6)
            ->get();

        if ($historyRows->isNotEmpty()) {
            $total = $historyRows->count();
            $accurate = $historyRows->filter(
                fn ($h) => abs($h->actual_next - $h->predicted_next) < config('ai.accuracy.accurate_threshold')
            )->count();
            $avgVariance = round($historyRows->avg(
                fn ($h) => $h->actual_next - $h->predicted_next
            ), 2);
            $accuracyPct = $total > 0 ? round($accurate / $total * 100, 1) : 0;

            $details = $historyRows->map(fn ($h) =>
                sprintf(
                    '  - Predicted %.1f%%, Actual %.1f%% (Variance: %+.1f%%)',
                    $h->predicted_next,
                    $h->actual_next,
                    $h->actual_next - $h->predicted_next
                )
            )->implode("\n");

            $historyAccuracy = "\nPREDICTION ACCURACY HISTORY:\n- Total predictions with actual outcomes: {$total}\n- Accurate within ±1%: {$accurate} ({$accuracyPct}%)\n- Average variance: {$avgVariance}%\n- Individual records:\n{$details}";
        }

        // Determine overall risk and trend from assessment
        $lastAssessment = !empty($assessment) ? end($assessment) : null;
        $riskLevel      = $lastAssessment['risk_level'] ?? 'Unknown';
        $trend          = $this->determineTrendFromAssessment($assessment);

        // Build completion multi section
        $completionMultiText = '';
        $cmMethods = $completionMulti['methods'] ?? [];
        $cmComparison = $completionMulti['comparison'] ?? [];
        if (!empty($cmMethods['polynomial'])) {
            $lines = [];
            foreach (['polynomial', 'schedule_aware', 'earned_schedule'] as $key) {
                $m = $cmMethods[$key] ?? null;
                if ($m && isset($m['estimated_completion_date'])) {
                    $label = $m['label_short'] ?? $key;
                    $date = $m['estimated_completion_date']->format('Y-m-d');
                    $delay = $m['predicted_delay_days'] ?? 0;
                    $conf = $m['completion_confidence'] ?? 'N/A';
                    $lines[] = "- {$label}: {$date} ({$delay} days, {$conf} confidence)";
                }
            }
            if (!empty($lines)) {
                $completionMultiText = "\nCOMPLETION ESTIMATES (3 METHODS):\n" . implode("\n", $lines);
                if (!empty($cmComparison['valid_count']) && $cmComparison['valid_count'] >= 2) {
                    $completionMultiText .= "\nComparison: Earliest={$cmComparison['earliest_date']->format('Y-m-d')}, Latest={$cmComparison['latest_date']->format('Y-m-d')}, Spread={$cmComparison['spread_days']}d, Agreement={$cmComparison['agreement']}";
                }
            }
        }

        // Build issues section (max 5 most recent)
        $issuesText = '';
        $recentIssues = $project->issues()->take(5)->get();
        if ($recentIssues->isNotEmpty()) {
            $issueLines = $recentIssues->map(fn ($i) =>
                "- {$i->issue_name} [{$i->status}]"
            )->implode("\n");
            $issuesText = "\nRECENT ISSUES:\n{$issueLines}";
        }

        $prompt = "You are a project management analyst. Given the following project data, write a structured analysis in exactly 5 sections. Use professional, neutral tone suitable for a contractor audience. Cover factual observations from the data.

PROJECT OVERVIEW:
- Title: {$project->title}
- Status: {$project->status}
- Current Progress: {$currentProgress}%
- Next Month Prediction (regression): {$nextProgress}%

REGRESSION MODEL:
- Method: {$regMethod}
- Equation: {$regEquation}
- Curve Fit Quality (matches past data only): R² = {$rSquared} ({$fitQuality})
- Based on {$obsCount} monthly progress observations
- Selection Reason: {$methodReason}
{$reliabilityText}{$historyAccuracy}
Do NOT use R-squared to compare model quality or prediction strength — it only describes how well the curve fits historical data points, nothing about whether future predictions will be correct. Use the FORECAST RELIABILITY score to assess prediction trust instead.
RISK ASSESSMENT:
- Overall Risk Level: {$riskLevel}
- Trend: {$trend}{$completionMultiText}{$issuesText}

OUTPUT FORMAT — Use exactly these 5 section markers (wrap each section title in [SECTION:Title] and [/SECTION]):
[SECTION:Report]
[2-3 paragraphs: Current project trajectory, key observations from data, what this means for project delivery]

[SECTION:Possible Risk]
[Bullet points: Key risk factors or negative indicators evident from the data. If none, state 'No significant risks identified from current data.']

[SECTION:Mitigation]
[Bullet points: Areas requiring closer monitoring or preventive measures. If none, state 'No immediate mitigation actions required.']

[SECTION:Performance]
[2-3 paragraphs: Assessment of project performance vs plan, progress trends, regression model reliability, forecast confidence]

[SECTION:AI Comment]
[1 paragraph: Clear bottom-line assessment of project health and outlook for the contractor]

Do NOT mention that you are an AI or that this is an AI-generated analysis. Write as a human project analyst reporting to the contractor. Avoid management jargon - write directly for the contractor who is executing the work.";

        // Plain-language guidance, appended after the unchanged prompt in
        // BOTH modes (user, 2026-09-21: once the reliability scores reached
        // the prompt, the analysis started reciting them — "too technical").
        $prompt .= $this->buildPlainLanguageAppendix();

        if (!$withRecommendations) {
            return $prompt;
        }

        return $prompt . $this->buildRecommendationsAppendix($project, $prediction);
    }

    /**
     * ------------------------------------------------------------------------
     * Build Plain-Language Appendix
     * ------------------------------------------------------------------------
     *
     * Tells the model to USE the technical inputs (reliability scores, fit
     * quality, method agreement…) to decide what to say and how confident to
     * sound, but to write the 5 sections in words a contractor or site
     * engineer reads without a statistics background. Concrete project facts
     * (progress %, dates, days, issue names) stay.
     *
     * Scope: the 5 analysis sections only — the recommendations appendix
     * and the standalone recommendation prompt are unchanged.
     *
     * Appended AFTER the original prompt so the original text stays
     * verbatim (a test pins the closing paragraph followed by this block).
     *
     * Called by: buildPrompt()
     */
    private function buildPlainLanguageAppendix(): string
    {
        return "

WRITING STYLE FOR THE 5 SECTIONS — keep them non-technical:
- Write in plain language that a contractor or site engineer understands without any statistics background.
- Use the technical data above (forecast reliability and its factor scores, curve fit / R², regression method and equation, method agreement, forecast horizon, prediction-accuracy percentages, class standing) only to decide what to say and how confident to sound. Do NOT quote those scores, percentiles or terms in the text.
- Say what they mean in everyday words instead, for example: \"the forecast for next month is fairly dependable\", \"the finish-date estimates disagree by about three months, so the end date is still uncertain\", \"past forecasts have usually been close to what was actually achieved\", \"progress is ahead of most similar projects\" (never write the word percentile or a percentile number).
- Keep the concrete project facts: progress percentages (actual, planned, next month's expected), dates, days remaining, issue names and counts, extension days.
- Prefer short sentences and bullet points over long statistical explanations.";
    }

    /**
     * ------------------------------------------------------------------------
     * Build Recommendations Appendix (combined prompt)
     * ------------------------------------------------------------------------
     *
     * Appended AFTER the unchanged analysis prompt so one Gemini call also
     * writes the recommendations (merge plan step 3):
     *   · the data only the recommendation prompt had (F-3), from
     *     AIRecommendationService::buildRecommendationContext()
     *   · the recommendation JSON shape + RULES, shared constants of
     *     AIRecommendationService (copied by reference, not by hand)
     *   · the one rule that is the point of combining: the recommendations
     *     follow from this answer's own Possible Risk + Mitigation (D5)
     *   · where the block goes: between RECS_OPEN / RECS_CLOSE, after the
     *     5 sections — cut out again by splitCombinedAnswer() before saving
     *
     * Called by: buildPrompt()
     */
    private function buildRecommendationsAppendix(Project $project, array $prediction): string
    {
        $context = $this->aiRecommendationService->buildRecommendationContext($project, $prediction);
        $jsonShape = AIRecommendationService::RECOMMENDATION_JSON_SHAPE;
        $rules = AIRecommendationService::RECOMMENDATION_RULES;
        $open = self::RECS_OPEN;
        $close = self::RECS_CLOSE;

        return "

RECOMMENDATIONS — After the 5 sections, also generate 3-6 prioritized, actionable recommendations. Each recommendation MUST reference specific data from the project. Do NOT generate generic advice.

{$context}

RECOMMENDATIONS OUTPUT FORMAT — After the [/SECTION] that closes AI Comment, write {$open} on its own line, then ONLY a valid JSON array (no markdown, no explanation), then {$close} on its own line:
{$open}
{$jsonShape}
{$close}

RECOMMENDATIONS RULES:
{$rules}
- The recommendations must be consistent with your Possible Risk and Mitigation sections — each should turn a risk or mitigation point into a concrete action. Do not contradict or merely repeat those sections.
- Put nothing but the JSON array between {$open} and {$close}, and write nothing after {$close}";
    }

    /**
     * ------------------------------------------------------------------------
     * Split Combined Answer
     * ------------------------------------------------------------------------
     *
     * Separates the one Gemini answer into the analysis text and the raw
     * recommendations block.
     *
     * analysis        — the answer with the block (and any code fence the
     *                   model wrapped it in) CUT OUT, trimmed. This is what is
     *                   stored as analysis_text, so it must never carry the
     *                   block: the Project Summary PDF prints analysis_text
     *                   RAW and the email draft copies it.
     * recommendations — the text between the markers, or null when there is
     *                   no block. An opening marker with no closing one (a
     *                   cut-off answer) takes everything after it.
     *
     * No marker at all: if what follows the last [/SECTION] looks like JSON
     * (the model forgot the markers but wrote the array), that tail is
     * treated as the block too, so it cannot leak into analysis_text.
     *
     * Called by: generate()
     *
     * @return array{analysis: string, recommendations: ?string}
     */
    private function splitCombinedAnswer(string $raw): array
    {
        $open = preg_quote(self::RECS_OPEN, '/');
        $close = preg_quote(self::RECS_CLOSE, '/');

        // Optional fence before the marker, body, closing marker (or end of
        // answer), optional fence after it.
        $pattern = '/(?:```[a-zA-Z]*[ \t]*\R?[ \t]*)?' . $open . '(.*?)(?:' . $close . '|\z)(?:[ \t]*\R?[ \t]*```)?/s';

        $blocks = [];
        $analysis = preg_replace_callback($pattern, function ($m) use (&$blocks) {
            $blocks[] = trim($m[1]);
            return '';
        }, $raw);

        if (!empty($blocks)) {
            return [
                'analysis'        => trim($analysis),
                'recommendations' => $blocks[0],
            ];
        }

        // Fallback: markers missing, but a JSON array follows the sections
        $lastSectionEnd = strrpos($raw, '[/SECTION]');
        if ($lastSectionEnd !== false) {
            $cut = $lastSectionEnd + strlen('[/SECTION]');
            $tail = trim(substr($raw, $cut));
            if ($tail !== '' && preg_match('/^(?:```[a-zA-Z]*\s*)?\[\s*\{/', $tail)) {
                return [
                    'analysis'        => trim(substr($raw, 0, $cut)),
                    'recommendations' => $tail,
                ];
            }
        }

        return ['analysis' => trim($raw), 'recommendations' => null];
    }

    /**
     * ------------------------------------------------------------------------
     * Build Reliability Text
     * ------------------------------------------------------------------------
     *
     * Renders the Phase 14 forecast-reliability block into a prompt-friendly
     * summary. The weighted reliability scores are the trust statement for the
     * AI; R² is intentionally demoted to a "curve fit, matches past data only"
     * note so the model never treats curve fit as forecast accuracy.
     *
     * Delegates to ReliabilityPromptText, the one renderer shared with the
     * recommendation prompt. The copy that lived here read a shape
     * ForecastReliabilityService never returns, so this block was always an
     * empty heading (fixed 2026-09-21, merge plan F-1 / decision D4). The
     * heading text is unchanged.
     *
     * Uses: reliability array from AIPredictionService
     */
    private function buildReliabilityText(?array $reliability): string
    {
        return ReliabilityPromptText::render($reliability, 'FORECAST RELIABILITY:');
    }

    /**
     * ------------------------------------------------------------------------
     * Determine Trend From Assessment
     * ------------------------------------------------------------------------
     *
     * Extracts the trend direction from the assessment array.
     * Compares variance between the last two assessment records.
     *
     * Uses: assessment array from AIPredictionService
     */
    private function determineTrendFromAssessment(array $assessment): string
    {
        if (count($assessment) < 2) {
            return 'Stable';
        }

        $latest   = end($assessment)['variance'];
        $previous = prev($assessment)['variance'];
        reset($assessment);

        $delta = $latest - $previous;

        $trendDelta = config('ai.trend.variance_delta', 2);

        if ($delta <= -$trendDelta) {
            return 'Declining (falling further behind)';
        }
        if ($delta >= $trendDelta) {
            return 'Improving (catching up)';
        }
        return 'Stable';
    }

    /**
     * ------------------------------------------------------------------------
     * Call Gemini API
     * ------------------------------------------------------------------------
     *
     * Sends the prompt to Google Gemini and returns the generated text.
     *
     * Handles:
     * - Empty/missing API key → return empty
     * - HTTP errors (4xx, 5xx) → return empty
     * - Timeouts (30s) → return empty
     * - Malformed response → return empty
     *
     * Logs one line per call (duration, HTTP status, prompt/answer length —
     * never the key or the URL) so the combined analysis+recommendations
     * answer can be measured against the 30 s timeout (merge plan step 2:
     * "re-measure before shipping").
     *
     * Uses: config/services.php (gemini key & model)
     *       Http facade for HTTP POST
     */
    private function callGemini(string $prompt, ?int $projectId = null): string
    {
        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model', 'gemini-1.5-flash');

        if (empty($apiKey)) {
            return '';
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $started = microtime(true);

        try {
            $response = Http::timeout(30)->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ]);

            $text = $response->successful()
                ? ($response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '')
                : '';

            Log::info('AI analysis: Gemini call', [
                'project_id'   => $projectId,
                'model'        => $model,
                'status'       => $response->status(),
                'ms'           => (int) round((microtime(true) - $started) * 1000),
                'prompt_chars' => mb_strlen($prompt),
                'answer_chars' => mb_strlen($text),
            ]);

            return $text;
        } catch (\Exception $e) {
            // Timeouts / connection errors land here — log the class only
            // (a Guzzle message can echo the URL, and the URL holds the key)
            Log::warning('AI analysis: Gemini call failed', [
                'project_id'   => $projectId,
                'model'        => $model,
                'error'        => get_class($e),
                'ms'           => (int) round((microtime(true) - $started) * 1000),
                'prompt_chars' => mb_strlen($prompt),
            ]);

            return '';
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Save Analysis to Database
     * ------------------------------------------------------------------------
     *
     * Creates a new AiSolution record with the generated text and a snapshot
     * of the regression data that was used.
     *
     * Uses: AiSolution model
     * Called by: generate()
     */
    private function saveAnalysis(
        Project $project,
        string $text,
        array $prediction,
        array $recommendations = []
    ): AiSolution {
        return AiSolution::create([
            'project_id'           => $project->id,
            'baseline_schedule_id' => $project->getPendingBaseline()?->id,
            'regression_snapshot'  => $prediction,
            'model_used'           => config('services.gemini.model', 'gemini-1.5-flash'),
            'analysis_text'        => $text,
            'recommendations'      => $recommendations ?: null,
        ]);
    }
}

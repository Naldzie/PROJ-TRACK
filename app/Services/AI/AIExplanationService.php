<?php

namespace App\Services\AI;

use App\Models\PredictionHistory;
use App\Models\Project;
use App\Models\AiSolution;
use Illuminate\Support\Facades\Http;

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
 * Depends On
 * ----------
 * AIPredictionService — provides the regression prediction data used to
 *                       build the AI prompt
 * AiSolution model    — persists generated analyses
 * config/services.php — Gemini API key and model name
 */
class AIExplanationService
{
    private AIPredictionService $aiPredictionService;

    private ?RecommendationEngine $recommendationEngine;

    /**
     * ------------------------------------------------------------------------
     * Constructor
     * ------------------------------------------------------------------------
     *
     * Inject AIPredictionService to retrieve regression data for the prompt.
     * Inject RecommendationEngine to generate and persist recommendations.
     *
     * @param  RecommendationEngine|null  $recommendationEngine  Optional; auto-resolved by container
     */
    public function __construct(
        AIPredictionService $aiPredictionService,
        ?RecommendationEngine $recommendationEngine = null
    ) {
        $this->aiPredictionService = $aiPredictionService;
        $this->recommendationEngine = $recommendationEngine ?? new RecommendationEngine();
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
     * 2. Build a structured prompt with project context + regression data
     * 3. Call the Gemini API
     * 4. On success → save to ai_solutions table, return the text
     * 5. On failure → return empty string (triggers static fallback)
     *
     * Called by: ProjectController::regenerateAnalysis() (Phase 6.3)
     * Uses: AIPredictionService, buildPrompt(), callGemini(), saveAnalysis()
     */
    public function generate(Project $project): string
    {
        // Run prediction pipeline — catch insufficient-data case
        try {
            $prediction = $this->aiPredictionService->generatePrediction($project);
        } catch (\InvalidArgumentException $e) {
            return '';
        }

        $prompt = $this->buildPrompt($project, $prediction);
        $text = $this->callGemini($prompt);

        if (empty($text)) {
            return '';
        }

        // Generate recommendations alongside analysis (Phase 10 enhancement)
        $recommendations = $this->recommendationEngine->generate($project, $prediction);

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
     * Uses: Project model, AIPredictionService output
     */
    private function buildPrompt(Project $project, array $prediction): string
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

        return "You are a project management analyst. Given the following project data, write a structured analysis in exactly 5 sections. Use professional, neutral tone suitable for a contractor audience. Cover factual observations from the data.

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
     * Uses: reliability array from AIPredictionService
     */
    private function buildReliabilityText(?array $reliability): string
    {
        if (empty($reliability)) {
            return "\nFORECAST RELIABILITY:\n- N/A — insufficient data for a reliability estimate";
        }

        $factorTitles = [
            'backtest_accuracy' => 'Prediction history',
            'data_sufficiency'  => 'Data',
            'forecast_horizon'  => 'Forecast horizon',
            'method_agreement'  => 'Method agreement',
        ];

        $lines = ['FORECAST RELIABILITY:'];

        if (isset($reliability['next_reliability'])) {
            $lines[] = sprintf(
                '- Next-month forecast: %s (%d/100)',
                $reliability['next_reliability']['label'],
                $reliability['next_reliability']['score']
            );
        }

        if (isset($reliability['completion_reliability'])) {
            $lines[] = sprintf(
                '- Completion estimate: %s (%d/100)',
                $reliability['completion_reliability']['label'],
                $reliability['completion_reliability']['score']
            );
        }

        $byKey = [];
        foreach ($reliability['factors'] ?? [] as $factor) {
            $byKey[$factor['key']] = $factor;
        }

        foreach ($factorTitles as $key => $title) {
            $factor = $byKey[$key] ?? null;
            if (!$factor) {
                continue;
            }
            $lines[] = sprintf(
                '- %s: %d/100 — %s',
                $title,
                $factor['score'],
                $factor['detail']
            );
        }

        return "\n" . implode("\n", $lines);
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
     * Uses: config/services.php (gemini key & model)
     *       Http facade for HTTP POST
     */
    private function callGemini(string $prompt): string
    {
        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model', 'gemini-1.5-flash');

        if (empty($apiKey)) {
            return '';
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

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

            if (!$response->successful()) {
                return '';
            }

            $data = $response->json();

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
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

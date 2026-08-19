<?php

namespace App\Services\AI;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Support\DateHelper;
use Carbon\Carbon;

/**
 * ============================================================================
 * AIPredictionPresenter
 * ============================================================================
 *
 * Purpose
 * -------
 * Transforms the raw AIPredictionService output into a blade-friendly
 * presentation format.
 *
 * This service handles:
 * • Completed project detection
 * • Insufficient data fallback
 * • Mapping regression/forecast/assessment data to display keys
 * • Calculating display-oriented values (expected progress, trend,
 *   speed comparison, recommendations)
 *
 * Used By
 * -------
 * ProjectDetailService (Phase 4)
 *
 * Depends On
 * ----------
 * AIPredictionService — provides the regression prediction pipeline
 */
class AIPredictionPresenter
{
    /**
     * ------------------------------------------------------------------------
     * AI Prediction Service
     * ------------------------------------------------------------------------
     *
     * Provides the completed regression prediction pipeline.
     */
    private AIPredictionService $aiPredictionService;

    /**
     * ------------------------------------------------------------------------
     * Recommendation Engine
     * ------------------------------------------------------------------------
     *
     * Generates structured, categorized, and explainable recommendations.
     */
    private RecommendationEngine $recommendationEngine;

    /**
     * ------------------------------------------------------------------------
     * Constructor
     * ------------------------------------------------------------------------
     *
     * Inject AI services used for presentation.
     */
    public function __construct(
        AIPredictionService $aiPredictionService,
        RecommendationEngine $recommendationEngine
    ) {
        $this->aiPredictionService = $aiPredictionService;
        $this->recommendationEngine = $recommendationEngine;
    }

    /**
     * ------------------------------------------------------------------------
     * Present Prediction Data
     * ------------------------------------------------------------------------
     *
     * Main entry point.
     *
     * Returns a structured array compatible with the existing
     * ai-prediction.blade.php view.
     *
     * Workflow
     * --------
     * Completed Project
     *      ↓
     * Return completion stats
     *
     * Ongoing Project
     *      ↓
     * Try AIPredictionService::generatePrediction()
     *      ↓
     * Success   → map normal prediction
     * Exception → return insufficient-data fallback
     *
     * Used By
     * -------
     * ProjectDetailService::buildAIPredictionData()
     */
    public function present(Project $project): array
    {
        /*
        |--------------------------------------------------------------------------
        | Completed Project
        |--------------------------------------------------------------------------
        |
        | Skip the regression pipeline entirely for completed projects.
        | Show actual completion statistics from the project record.
        |
        */

        if ($project->status === 'Completed') {
            return $this->presentCompleted($project);
        }

        /*
        |--------------------------------------------------------------------------
        | Ongoing Project — Attempt Regression
        |--------------------------------------------------------------------------
        |
        | Catch InvalidArgumentException (fewer than 2 observations)
        | and return an insufficient-data response.
        |
        */

        try {
            $prediction = $this->aiPredictionService->generatePrediction($project);
        } catch (\InvalidArgumentException $e) {
            return $this->presentInsufficientData($project);
        }

        /*
        |--------------------------------------------------------------------------
        | Edge Case: Insufficient Data
        |--------------------------------------------------------------------------
        |
        | The prediction service may return a minimal report when there are
        | fewer than 2 observations (instead of throwing). Route to the
        | insufficient-data branch to show appropriate messaging.
        |
        */

        $observationCount = (int) ($prediction['statistics']['observation_count'] ?? 0);

        if ($observationCount < 2) {
            return $this->presentInsufficientData($project);
        }

        /*
        |--------------------------------------------------------------------------
        | Normal Prediction
        |--------------------------------------------------------------------------
        |
        | Map the AIPredictionService output to blade-compatible keys.
        |
        */

        return $this->presentNormal($project, $prediction);
    }

    /**
     * ------------------------------------------------------------------------
     * Present Completed Project
     * ------------------------------------------------------------------------
     *
     * Returns completion statistics from the project record.
     *
     * Used By
     * -------
     * present()
     */
    private function presentCompleted(Project $project): array
    {
        return [
            /*
            |------------------------------------------------------------------
            | Legacy Flags
            |------------------------------------------------------------------
            |
            | is_mockup  → true preserves backward compatibility with blade
            |              checks. The data is no longer a "mockup" but the
            |              blade was written for this key.
            |
            | is_completed → true triggers the completed-project branch in
            |               the blade view.
            |
            */

            'is_mockup'          => true,
            'is_completed'       => true,

            /*
            |------------------------------------------------------------------
            | Completion Dates
            |------------------------------------------------------------------
            */

            'actual_completion'          => $project->actual_end_date,
            'planned_completion'         => $project->planned_end_date,
            'completion_delay'           => $project->getCompletionDelay(),
            'structured_recommendations' => [],
            'prediction_history'         => [],
            'prediction_history_summary' => [
                'total_with_actual' => 0,
                'accurate_count'    => 0,
                'accuracy_percent'  => 0,
                'avg_variance'      => 0,
            ],
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Present Insufficient Data
     * ------------------------------------------------------------------------
     *
     * Returns a limited view when regression cannot run
     * (fewer than 2 monthly observations).
     *
     * Used By
     * -------
     * present()
     */
    private function presentInsufficientData(Project $project): array
    {
        $currentProgress   = $project->getProgressPercentage();
        $daysElapsed       = $this->getDaysElapsed($project);
        $daysTotal         = $this->getDaysTotal($project);
        $daysRemaining     = $this->calculateDaysRemaining($project);
        $expectedProgress  = app(\App\Services\ProjectHealthService::class)->expectedProgress($project);
        $progressGap       = $expectedProgress - $currentProgress;

        return [
            /*
            |------------------------------------------------------------------
            | Legacy Flags
            |------------------------------------------------------------------
            */

            'is_mockup'              => true,
            'is_completed'           => false,
            'is_insufficient_data'   => true,

            /*
            |------------------------------------------------------------------
            | Status
            |------------------------------------------------------------------
            */

            'status'                 => 'INSUFFICIENT DATA',
            'risk_level'             => 'unknown',
            'status_color'           => 'gray',

            /*
            |------------------------------------------------------------------
            | Progress Metrics
            |------------------------------------------------------------------
            */

            'current_progress'       => round($currentProgress, 1),
            'expected_progress'      => round($expectedProgress, 1),
            'progress_gap'           => round($progressGap, 1),

            /*
            |------------------------------------------------------------------
            | Timeline
            |------------------------------------------------------------------
            */

            'days_elapsed'           => $daysElapsed,
            'days_total'             => $daysTotal,
            'days_remaining'         => max(0, $daysRemaining),
            'estimated_completion'   => null,
            'can_estimate_completion'=> false,
            'planned_completion'     => $project->planned_end_date,
            'predicted_delay_days'   => 0,

            /*
            |------------------------------------------------------------------
            | Certainty
            |------------------------------------------------------------------
            */

            'certainty_label'        => 'Insufficient Data',
            'confidence_percentage'  => null,

            /*
            |------------------------------------------------------------------
            | Trend
            |------------------------------------------------------------------
            */

            'trend'                  => 'STABLE',
            'trend_label'            => 'Not enough data to determine a trend',

            /*
            |------------------------------------------------------------------
            | Milestones & Recommendations
            |------------------------------------------------------------------
            */

            'behind_milestone'       => null,
            'recommendations'            => [
                'At least 2 monthly progress records are needed before the system can estimate a completion trend.',
                'Continue regular monthly inspections to enable trend estimation.',
            ],
            'structured_recommendations' => [],

            /*
            |------------------------------------------------------------------
            | Speed Comparison
            |------------------------------------------------------------------
            */

            'early_speed'            => 0,
            'recent_speed'           => 0,

            /*
            |------------------------------------------------------------------
            | Analysis
            |------------------------------------------------------------------
            */

            'analysis_text'          => 'Not enough inspection records yet to estimate a completion trend for this project.',

            /*
            |------------------------------------------------------------------
            | New Prediction Data (empty)
            |------------------------------------------------------------------
            |
            | These keys are available even in the insufficient-data state
            | so the blade can conditionally show/hide sections.
            |
            */

            'regression_info'        => null,
            'forecast_data'          => [],
            'assessment_data'        => [],
            'completion_multi'       => [],
            'next_prediction'        => null,
            'next_prediction_low'    => null,
            'next_prediction_high'   => null,
            'next_prediction_margin' => null,
            'forecast_reliability'   => null,
            'next_prediction_reliability' => null,
            'completion_reliability' => null,
            'reliability_factors'    => [],
            'fit_quality_label'      => 'Curve Fit Quality',
            'next_planned_progress'  => null,
            'next_is_current_month'  => false,
            'current_month_index'    => 0,
            'prediction_history'     => [],
            'prediction_history_summary' => [
                'total_with_actual' => 0,
                'accurate_count'    => 0,
                'accuracy_percent'  => 0,
                'avg_variance'      => 0,
            ],
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Present Normal Prediction
     * ------------------------------------------------------------------------
     *
     * Maps the AIPredictionService output to blade-compatible keys
     * and enriches it with display-oriented calculations.
     *
     * Used By
     * -------
     * present()
     */
    private function presentNormal(Project $project, array $prediction): array
    {
        /*
        |--------------------------------------------------------------------------
        | Extract AIPredictionService Data
        |--------------------------------------------------------------------------
        */

        $currentState      = $prediction['current_state'];
        $nextPrediction    = $prediction['next_prediction'];
        $forecast          = $prediction['forecast'];
        $assessment        = $prediction['assessment'];
        $regression        = $prediction['regression'];
        $statistics        = $prediction['statistics'];
        $completion        = $prediction['completion'] ?? [];
        $completionMulti   = $prediction['completion_multi'] ?? [];

        // Phase 14: forecast reliability block (weighted evidence score).
        $reliability          = $prediction['reliability'] ?? null;
        $nextReliability      = $reliability['next_prediction'] ?? null;
        $completionReliability = $reliability['completion'] ?? null;
        $fitQualityLabel      = $reliability['fit_quality_label'] ?? 'Curve Fit Quality';
        $reliabilityFactors   = $this->flattenReliabilityFactors($nextReliability);

        $currentProgress   = $currentState['progress'];
        $currentMonthIndex = $currentState['month_index'];

        /*
        |--------------------------------------------------------------------------
        | Prediction History (Phase 7)
        |--------------------------------------------------------------------------
        |
        | Load past predictions that have actual outcomes for accuracy
        | display in the Blade view. Limited to 6 most recent records.
        |
        */

        // Rev 5 (keep-latest): show ONE entry per month — the newest forecast
        // row for EVERY distinct month (no cap), sorted newest → oldest so the
        // table matches the progress-history table (newest on top).
        $predictionHistory = $project->predictionHistory()
            ->whereNotNull('month_label')
            ->orderBy('month_label')
            ->orderByDesc('id')
            ->get()
            ->groupBy('month_label')
            ->map(function ($group) {
                $latest    = $group->first(); // ordered id desc → first = newest forecast
                $hasActual = $latest->actual_next !== null;

                return [
                    'month_label'    => $latest->month_label,
                    'month'          => \Carbon\Carbon::parse($latest->month_label . '-01')->format('M Y'),
                    'predicted'      => $latest->predicted_next,
                    'predicted_low'  => $latest->predicted_low,
                    'predicted_high' => $latest->predicted_high,
                    'actual'         => $hasActual ? $latest->actual_next : null,
                    'variance'       => $hasActual ? round($latest->actual_next - $latest->predicted_next, 2) : null,
                    'method'         => $latest->regression_method,
                    'pending'        => !$hasActual,
                ];
            })
            ->sortByDesc('month_label')
            ->values();

        $withActual      = $predictionHistory->filter(fn ($h) => !$h['pending']);
        $totalWithActual = $withActual->count();
        $accurateCount   = $withActual->filter(
            fn ($h) => abs($h['variance']) < 2
        )->count();
        $avgVariance     = $totalWithActual > 0
            ? round($withActual->avg('variance'), 2)
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Calculate Display Values
        |--------------------------------------------------------------------------
        |
        | These values are NOT part of the regression pipeline.
        | They are derived here for UI display purposes.
        |
        */

        $daysElapsed       = $this->getDaysElapsed($project);
        $daysTotal         = $this->getDaysTotal($project);
        $daysRemaining     = $this->calculateDaysRemaining($project);
        $healthService     = app(\App\Services\ProjectHealthService::class);
        $expectedProgress  = $healthService->expectedProgress($project);
        $progressGap       = $expectedProgress - $currentProgress;

        /*
        |--------------------------------------------------------------------------
        | Detect if Next Prediction is Actually for the Current Month
        |--------------------------------------------------------------------------
        |
        | The AI predicts progress for the month after the latest recorded
        | history. If that predicted month matches the current (simulated)
        | month, the label should read "This Month" instead of "Next Month."
        |
        */

        $nextIsCurrentMonth = false;
        $latestHistory = ProjectProgressHistory::where('project_id', $project->id)
            ->orderBy('month_label', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($latestHistory && $latestHistory->month_label) {
            $predictedMonth = \Illuminate\Support\Carbon::parse($latestHistory->month_label . '-01')->addMonth();
            $nextIsCurrentMonth = $predictedMonth->format('Y-m') === DateHelper::now()->format('Y-m');
        }

        /*
        |--------------------------------------------------------------------------
        | Determine Risk Status
        |--------------------------------------------------------------------------
        |
        | Risk is based solely on the current progress gap (actual vs planned
        | at today's date). This reflects the real on-the-ground situation.
        |
        | The gap naturally incorporates time: expectedProgress is recalculated
        | each page load using DateHelper::now(), so as days pass without
        | progress updates, the gap widens and risk escalates automatically.
        |
        | Forecast/prediction data is NOT mixed into health status — it
        | remains available separately in assessment_data for the forecast
        | table and analysis sections.
        |
        */

        $currentGapRisk = $healthService->classifyGap($progressGap);

        $overallRisk = $currentGapRisk;

        /*
        |--------------------------------------------------------------------------
        | Map Risk to Status Display
        |--------------------------------------------------------------------------
        |
        | Only 'Low' risk maps to ON TRACK. Everything else (Moderate,
        | High, Critical) is AT RISK — being up to 10% behind schedule
        | is a genuine concern, not "on track".
        |
        */

        $statusDisplay = $overallRisk === 'Low' ? 'ON TRACK' : 'AT RISK';
        $statusColor   = $overallRisk === 'Low' ? '#10b981' : '#f59e0b';

        /*
        |--------------------------------------------------------------------------
        | Determine Certainty Label
        |--------------------------------------------------------------------------
        |
        | Based on observation count and R².
        |
        */

        $observationCount = (int) ($statistics['observation_count'] ?? 0);
        $rSquared         = (float) ($regression['r_squared'] ?? 0);
        $certaintyLabel   = $this->generateCertaintyLabel($observationCount, $rSquared);

        /*
        |--------------------------------------------------------------------------
        | Health Confidence (Observation-Based)
        |--------------------------------------------------------------------------
        |
        | Uses real observation count only (excluding synthetic points from
        | cross-project supplement). This measures how much REAL data supports
        | the health assessment — not regression fit quality.
        |
        */

        $syntheticCount = (int) ($regression['synthetic_points_count'] ?? 0);
        $realObsCount   = max(0, $observationCount - $syntheticCount);
        $healthConfidence = match (true) {
            $realObsCount >= 6 => 'High',
            $realObsCount >= 3 => 'Medium',
            default            => 'Low',
        };

        /*
        |--------------------------------------------------------------------------
        | Pure Category Baseline (A4, v2.6 §5.6)
        |--------------------------------------------------------------------------
        |
        | data_source 'similar_projects' with 0 real observations = the curve is
        | 100% the category reference, NOT the project's own health. The health
        | overview (gap, status, trend, gauge) must therefore stay honest —
        | "no actual progress data yet" — while the baseline curve + next-month
        | band still render below, clearly labeled.
        |
        */

        $dataSource       = (string) ($regression['data_source'] ?? 'project_history');
        $isCategoryBaseline = $dataSource === 'similar_projects' && $realObsCount <= 0;

        /*
        |--------------------------------------------------------------------------
        | Determine Trend
        |--------------------------------------------------------------------------
        |
        | Compares the last two assessment records (variance trend)
        | to classify the project as ACCELERATING, DECELERATING,
        | or STABLE.
        |
        */

        $trendResult  = $this->determineTrend($assessment);
        $trend        = $trendResult['trend'];
        $trendLabel   = $trendResult['label'];

        /*
        |--------------------------------------------------------------------------
        | Calculate Speed Comparison
        |--------------------------------------------------------------------------
        |
        | Compares the average actual progress in the first half
        | of the project history against the second half.
        |
        */

        $speedResult  = $this->calculateSpeedComparison($project, $observationCount);
        $earlySpeed   = $speedResult['early_speed'];
        $recentSpeed  = $speedResult['recent_speed'];

        /*
        |--------------------------------------------------------------------------
        | Honest Health Override for Pure Category Baseline (A4)
        |--------------------------------------------------------------------------
        |
        | The health overview must reflect the project's REAL state. With 0 real
        | observations there is no actual progress, no measurable gap-to-plan,
        | and no trend — the baseline curve is a reference, not the project's
        | own performance (Rule: never present a borrowed curve as the
        | project's own health).
        |
        */

        if ($isCategoryBaseline) {
            // Real progress (0 records → 0%), not the baseline's month-1 value.
            $currentProgress = (float) $project->getProgressPercentage();
            $progressGap     = round($expectedProgress - $currentProgress, 1);
            $overallRisk     = 'unknown';
            $statusDisplay   = 'NO ACTUAL DATA';
            $statusColor     = '#9ca3af';
            $trend           = 'N/A';
            $trendLabel      = 'Not enough data to determine a trend';
            $earlySpeed      = 0;
            $recentSpeed     = 0;
            $healthConfidence = 'Low';

            // Certainty "Good data, strong fit" would be misleading — the R²
            // describes a fit to the category reference, not this project.
            $certaintyLabel  = 'Category baseline estimate';

            // The timeline "Est. Completion" must not show a finish date
            // extrapolated from the borrowed curve — there is no real data
            // behind it.
            $completion['estimated_completion_date'] = null;
            $completion['predicted_delay_days']      = 0;
            $completion['completion_confidence']     = null;
            $completionReliability                   = null;

            // Never let stale cached AI analysis text claim this project has
            // real observed progress — force the honest static baseline text.
            $latestAi = null;
            $analysisText = 'No actual progress observations recorded for this project yet. '
                . 'The estimate below is a category baseline reference built from '
                . $syntheticCount . ' synthetic curve points across '
                . (int) ($regression['similar_projects_count'] ?? 0)
                . ' similar completed projects in this category — it is NOT a '
                . 'statement about this project\'s own performance. Record monthly '
                . 'inspections to replace it with a project-specific forecast.';
            $aiGeneratedAt = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Recommendations
        |--------------------------------------------------------------------------
        |
        | Uses the RecommendationEngine (rule-based) to produce structured,
        | categorized recommendations with reasons and priorities.
        | Falls back to empty arrays when prediction data is insufficient.
        |
        */

        $structuredRecs = $this->recommendationEngine->generate($project, $prediction);
        $recommendations = array_column($structuredRecs, 'message');

        /*
        |--------------------------------------------------------------------------
        | Analysis Text (AI or Static)
        |--------------------------------------------------------------------------
        |
        | Use cached AI-generated text from ai_solutions table if available.
        | Falls back to static template text when no AI solution exists.
        |
        | Uses: Project::aiSolutions() relationship
        */

        $latestAi = null;
        if (!$isCategoryBaseline) {
            $latestAi = $project->aiSolutions()->first();
            if ($latestAi && $latestAi->analysis_text) {
                $analysisText = $latestAi->analysis_text;
                $aiGeneratedAt = $latestAi->created_at;
            } else {
                $analysisText = $this->generateAnalysisText(
                    $project,
                    $currentProgress,
                    $expectedProgress,
                    $statusDisplay
                );
                $aiGeneratedAt = null;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Build Return Array
        |--------------------------------------------------------------------------
        */

        return [

            /*
            |------------------------------------------------------------------
            | Legacy Flags
            |------------------------------------------------------------------
            */

            'is_mockup'              => true,
            'is_completed'           => false,
            'is_insufficient_data'   => false,
            // A4 (v2.6 §5.6): pure category-baseline (data_source
            // 'similar_projects', 0 real observations). Health overview stays
            // honest (status 'NO ACTUAL DATA', grey gauge, no trend) while the
            // baseline curve + next-month band render below as a reference.
            'is_category_baseline'   => $isCategoryBaseline,

            /*
            |------------------------------------------------------------------
            | Status
            |------------------------------------------------------------------
            */

            'status'                 => $statusDisplay,
            'risk_level'             => $overallRisk,
            'status_color'           => $statusColor,

            /*
            |------------------------------------------------------------------
            | Progress Metrics
            |------------------------------------------------------------------
            */

            'current_progress'       => round($currentProgress, 1),
            'expected_progress'      => round($expectedProgress, 1),
            'progress_gap'           => round($progressGap, 1),

            /*
            |------------------------------------------------------------------
            | Timeline
            |------------------------------------------------------------------
            */

            'days_elapsed'           => $daysElapsed,
            'days_total'             => $daysTotal,
            'days_remaining'         => max(0, $daysRemaining),

            'estimated_completion'    => $completion['estimated_completion_date'] ?? null,
            'can_estimate_completion' => ($completion['estimated_completion_date'] ?? null) !== null,
            'planned_completion'    => $project->planned_end_date,
            'predicted_delay_days'    => $completion['predicted_delay_days'] ?? 0,
            'completion_confidence'   => $completion['completion_confidence'] ?? null,
            'health_confidence'      => $healthConfidence,

            /*
            |------------------------------------------------------------------
            | Certainty
            |------------------------------------------------------------------
            */

            'certainty_label'        => $certaintyLabel,
            'confidence_percentage'  => null,

            /*
            |------------------------------------------------------------------
            | Trend
            |------------------------------------------------------------------
            */

            'trend'                  => $trend,
            'trend_label'            => $trendLabel,

            /*
            |------------------------------------------------------------------
            | Milestones & Recommendations
            |------------------------------------------------------------------
            */

            'behind_milestone'       => null,
            'recommendations'            => $recommendations,
            'structured_recommendations' => $structuredRecs,

            /*
            |------------------------------------------------------------------
            | Speed Comparison
            |------------------------------------------------------------------
            */

            'early_speed'            => $earlySpeed,
            'recent_speed'           => $recentSpeed,

            /*
            |------------------------------------------------------------------
            | Analysis
            |------------------------------------------------------------------
            */

            'analysis_text'          => $analysisText,

            /*
            |------------------------------------------------------------------
            | AI Metadata
            |------------------------------------------------------------------
            */

            'ai_generated_at'        => $aiGeneratedAt,

            /*
            |------------------------------------------------------------------
            | New Prediction Data (Phase 4)
            |------------------------------------------------------------------
            |
            | These keys provide access to the full AIPredictionService
            | output for enhanced Blade display (e.g., showing R²,
            | regression equation, forecast breakdown).
            |
            | Used By
            | -------
            | ai-prediction.blade.php (Phase 4.5)
            |
            */

            'regression_info'        => [
                'equation'           => $regression['equation'],
                'slope'              => $regression['slope'] ?? null,
                'intercept'          => $regression['intercept'] ?? null,
                'r_squared'          => $regression['r_squared'],
                'fit_quality'        => $regression['fit_quality'],
                // v2.6 4.4 — honest fit wording: when the model is blended
                // with similar projects, the synthetic-derived R² must NOT be
                // presented as "Excellent curve fit". The blade shows this
                // display string instead of the raw fit_quality classification.
                'fit_quality_display' => $this->presentFitQualityDisplay($regression),
                'observation_count'  => $regression['observation_count'],
                'regression_method'  => $regression['regression_method'] ?? 'slr',
                'method_reason'      => $regression['method_reason'] ?? null,
                'r_squared_slr'      => $regression['r_squared_slr'] ?? null,
                'r_squared_poly'     => $regression['r_squared_poly'] ?? null,
                'a'                  => $regression['a'] ?? null,
                'b'                  => $regression['b'] ?? null,
                'c'                  => $regression['c'] ?? null,
                'd'                  => $regression['d'] ?? null,
                'coefficients'       => $regression['coefficients'] ?? null,
                'data_source'        => $regression['data_source'] ?? 'project_history',
                'similar_projects_count' => $regression['similar_projects_count'] ?? null,
                'similar_projects_details' => $regression['similar_projects_details'] ?? null,
                'similar_projects_disclaimer' => $regression['similar_projects_disclaimer'] ?? null,
                'synthetic_points_count' => $regression['synthetic_points_count'] ?? null,
                'fit_quality_label'  => $fitQualityLabel,
            ],

            'completion_multi'       => $completionMulti,

            /*
            |------------------------------------------------------------------
            | Class Standing (B5/B6)
            |------------------------------------------------------------------
            |
            | B5-lite banded percentile tier + B6 at-risk flag. Passed through
            | from AIPredictionService::buildClassStanding(); the blade renders
            | nothing when 'usable' is false.
            */

            'class_standing' => $prediction['class_standing'] ?? [
                'usable'        => false,
                'similar_count' => 0,
                'tier'          => null,
                'tier_label'    => null,
                'at_risk'       => false,
                'at_risk_run'   => 0,
                'lower_band'    => null,
                'median_band'   => null,
                'upper_band'    => null,
            ],

            /*
            |--------------------------------------------------------------
            | Prediction History (Phase 7)
            |--------------------------------------------------------------
            */

            'prediction_history'         => $predictionHistory,
            'prediction_history_summary' => [
                'total_with_actual' => $totalWithActual,
                'accurate_count'    => $accurateCount,
                'accuracy_percent'  => $totalWithActual > 0
                    ? round($accurateCount / $totalWithActual * 100, 1)
                    : 0,
                'avg_variance'      => $avgVariance,
            ],

            'forecast_data'          => $forecast,
            'assessment_data'        => $assessment,
            'next_prediction'        => $nextPrediction['progress'],

            /*
            |------------------------------------------------------------------
            | Next-Month Prediction Band (Phase 14 Step 1)
            |------------------------------------------------------------------
            |
            | The next-month forecast is a range (point ± margin), never a
            | bare single %. Keys stay additive — next_prediction remains the
            | scalar for backward compatibility.
            |
            */

            'next_prediction_low'    => $nextPrediction['progress_low'] ?? null,
            'next_prediction_high'   => $nextPrediction['progress_high'] ?? null,
            'next_prediction_margin' => $nextPrediction['margin'] ?? null,

            /*
            |------------------------------------------------------------------
            | Forecast Reliability (Phase 14)
            |------------------------------------------------------------------
            |
            | Weighted, explainable reliability for the next-month prediction
            | and the completion estimate, plus a flattened factor list for the
            | "why" tooltip. Existing keys (completion_confidence, etc.) stay.
            |
            */

            'forecast_reliability'      => $reliability,
            'next_prediction_reliability' => $nextReliability,
            'completion_reliability'    => $completionReliability,
            'reliability_factors'       => $reliabilityFactors,
            'fit_quality_label'         => $fitQualityLabel,
            'next_planned_progress'  => $this->getNextPlannedProgress($project),
            'next_is_current_month'  => $nextIsCurrentMonth,
            'current_month_index'    => $currentMonthIndex,
        ];
    }

    /*
    |==========================================================================
    | Display Calculation Helpers
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Flatten Reliability Factors
     * ------------------------------------------------------------------------
     *
     * Turns the reliability block's per-factor breakdown into a blade-friendly
     * list (factor name, score, detail) for the "why" tooltip on the next-month
     * forecast card.
     *
     * Used By
     * -------
     * presentNormal()
     */
    private function flattenReliabilityFactors(?array $reliability): array
    {
        $factors = $reliability['factors'] ?? [];

        $labels = [
            'backtest'  => 'Backtest accuracy',
            'data'      => 'Data sufficiency',
            'horizon'   => 'Forecast horizon',
            'agreement' => 'Method agreement',
        ];

        $flattened = [];

        foreach ($labels as $key => $label) {
            if (!isset($factors[$key])) {
                continue;
            }

            $flattened[] = [
                'factor' => $label,
                'score'  => $factors[$key]['score'] ?? 0,
                'detail' => $factors[$key]['detail'] ?? '',
            ];
        }

        return $flattened;
    }

    /**
     * ------------------------------------------------------------------------
     * Get Next Planned Progress
     * ------------------------------------------------------------------------
     *
     * Returns the planned_progress_percent from the baseline schedule
     * immediately after the latest recorded progress month.
     *
     * Used By
     * -------
     * presentNormal()
     */
    private function getNextPlannedProgress(Project $project): ?float
    {
        $latestHistory = ProjectProgressHistory::where('project_id', $project->id)
            ->orderBy('month_label', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$latestHistory || !$latestHistory->month_label) {
            return null;
        }

        $nextBaseline = ProjectBaselineSchedule::where('project_id', $project->id)
            ->where('month_label', '>', $latestHistory->month_label)
            ->orderBy('month_label', 'asc')
            ->first();

        return $nextBaseline ? (float) $nextBaseline->planned_progress_percent : null;
    }

    /**
     * ------------------------------------------------------------------------
     * Present Fit Quality Display (v2.6 4.4)
     * ------------------------------------------------------------------------
     *
     * When the regression was blended with similar completed projects
     * (data_source 'hybrid' or 'similar_projects'), the synthetic-derived R²
     * is NOT a statement about the project's own history — so presenting it
     * as "Excellent curve fit" would be misleading. Returns a reference-class
     * wording ("Model blended with category reference curve (N=3 similar
     * projects)") instead. For a project's own history, the raw
     * fit_quality classification is returned unchanged.
     */
    private function presentFitQualityDisplay(array $regression): string
    {
        $dataSource = $regression['data_source'] ?? 'project_history';

        if ($dataSource === 'project_history') {
            return (string) ($regression['fit_quality'] ?? 'N/A');
        }

        $count = (int) ($regression['similar_projects_count'] ?? 0);

        // A4 (v2.6 §5.6): a pure category-baseline fallback (data_source
        // 'similar_projects' with 0 real observations) is NOT a "blend" — the
        // curve is entirely the category reference. Real obs =
        // observation_count − synthetic_points_count, which is 0 exactly when
        // the fallback replaced an empty dataset.
        if ($dataSource === 'similar_projects') {
            $realObs = (int) ($regression['observation_count'] ?? 0)
                - (int) ($regression['synthetic_points_count'] ?? 0);

            if ($realObs <= 0) {
                return $count > 0
                    ? "Category baseline estimate ({$count} similar projects) — not project-specific"
                    : 'Category baseline estimate — not project-specific';
            }

            return $count > 0
                ? "Model blended with category reference curve ({$count} similar projects)"
                : 'Model blended with category reference curve';
        }

        // hybrid
        return $count > 0
            ? "Model blended with category reference curve ({$count} similar projects)"
            : 'Model blended with category reference curve';
    }

    /**
     * ------------------------------------------------------------------------
     * Generate Certainty Label
     * ------------------------------------------------------------------------
     *
     * Produces a human-readable certainty description based on
     * observation count and R².
     *
     * Used By
     * -------
     * presentNormal()
     */
    private function generateCertaintyLabel(
        int $observationCount,
        float $rSquared
    ): string {
        $dataCertainty = match (true) {
            $observationCount <= 3  => 'Low data',
            $observationCount <= 6  => 'Moderate data',
            default                 => 'Good data',
        };

        $fitCertainty = match (true) {
            $rSquared >= 0.9  => 'strong fit',
            $rSquared >= 0.7  => 'good fit',
            $rSquared >= 0.5  => 'moderate fit',
            default           => 'weak fit',
        };

        return "{$dataCertainty}, {$fitCertainty}";
    }

    /**
     * ------------------------------------------------------------------------
     * Determine Trend
     * ------------------------------------------------------------------------
     *
     * Classifies the project trend as ACCELERATING, DECELERATING,
     * or STABLE by comparing the last two assessment records.
     *
     * Used By
     * -------
     * presentNormal()
     */
    private function determineTrend(array $assessment): array
    {
        if (count($assessment) < 2) {
            return [
                'trend' => 'STABLE',
                'label' => 'Steady progress',
            ];
        }

        $latestVariance   = end($assessment)['variance'];
        $previousVariance = prev($assessment)['variance'];
        reset($assessment);

        $delta = $latestVariance - $previousVariance;

        if ($delta <= -2) {
            return [
                'trend' => 'DECELERATING',
                'label' => 'Falling further behind schedule compared to last month',
            ];
        }

        if ($delta >= 2) {
            return [
                'trend' => 'ACCELERATING',
                'label' => 'Catching up to baseline schedule compared to last month',
            ];
        }

        return [
            'trend' => 'STABLE',
            'label' => 'Steady progress',
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Speed Comparison
     * ------------------------------------------------------------------------
     *
     * Splits the project history in half and compares the average
     * cumulative progress of the early phase against the recent phase.
     *
     * Used By
     * -------
     * presentNormal()
     */
    private function calculateSpeedComparison(
        Project $project,
        int $observationCount
    ): array {
        /*
        |----------------------------------------------------------------------
        | Fetch Progress History
        |----------------------------------------------------------------------
        |
        | Uses actual_progress_percent from the progress history.
        |
        */

        $progressHistory = $project->progressHistory()
            ->orderBy('month_label', 'asc')
            ->get();

        if ($progressHistory->isEmpty()) {
            return [
                'early_speed'  => 0,
                'recent_speed' => 0,
            ];
        }

        $half = max(1, (int) ceil($observationCount / 2));

        $earlyAvg = $progressHistory->slice(0, $half)
            ->avg(fn ($r) => (float) ($r->actual_progress_percent ?? 0));

        $recentAvg = $progressHistory->slice(-$half)
            ->avg(fn ($r) => (float) ($r->actual_progress_percent ?? 0));

        return [
            'early_speed'  => round($earlyAvg, 1),
            'recent_speed' => round($recentAvg, 1),
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Generate Analysis Text
     * ------------------------------------------------------------------------
     *
     * Produces a human-readable summary of the project's current
     * state based on available data.
     *
     * Used By
     * -------
     * presentNormal()
     */
    private function generateAnalysisText(
        Project $project,
        float $currentProgress,
        float $expectedProgress,
        string $statusDisplay
    ): string {
        $gap = round($expectedProgress - $currentProgress, 1);

        if ($statusDisplay === 'ON TRACK') {
            return "Project is progressing well. Current progress ({$currentProgress}%) "
                 . "is close to the expected progress ({$expectedProgress}%). "
                 . "At this rate, the project is expected to complete on schedule.";
        }

        if ($gap < 0) {
            return "Excellent! Project is ahead of schedule. Current progress ({$currentProgress}%) "
                 . "exceeds the expected progress ({$expectedProgress}%).";
        }

        return "Project is running behind schedule. Current progress ({$currentProgress}%) "
             . "is {$gap}% below expected progress ({$expectedProgress}%). "
             . "The regression model indicates variance is likely to persist "
             . "based on historical trend analysis. Immediate action is recommended.";
    }

    /*
    |==========================================================================
    | Timeline Helpers
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Get Days Elapsed
     * ------------------------------------------------------------------------
     *
     * Calculates the number of days since the project's planned start date.
     *
     * Used By
     * -------
     * presentNormal(), presentInsufficientData()
     */
    private function getDaysElapsed(Project $project): int
    {
        if (!$project->planned_start_date) {
            return 0;
        }
        return (int) $project->planned_start_date->diffInDays(DateHelper::now(), false);
    }

    /**
     * ------------------------------------------------------------------------
     * Get Days Total
     * ------------------------------------------------------------------------
     *
     * Calculates the total planned duration in days.
     *
     * Used By
     * -------
     * presentNormal(), presentInsufficientData()
     */
    private function getDaysTotal(Project $project): int
    {
        if (!$project->planned_start_date || !$project->planned_end_date) {
            return 0;
        }
        return (int) $project->planned_start_date->diffInDays($project->planned_end_date, false);
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Days Remaining
     * ------------------------------------------------------------------------
     *
     * Calculates the number of days remaining until the planned end date.
     *
     * Used By
     * -------
     * presentNormal(), presentInsufficientData()
     */
    private function calculateDaysRemaining(Project $project): int
    {
        if (!$project->planned_end_date) {
            return 0;
        }
        return (int) max(0, floor(DateHelper::now()->diffInDays($project->planned_end_date, false)));
    }
}

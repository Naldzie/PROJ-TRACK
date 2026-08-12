<?php

namespace App\Services\AI;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Services\AI\Regression\RegressionDatasetBuilder;
use App\Services\AI\Regression\LinearRegressionService;
use App\Services\AI\Regression\PolynomialRegressionService;
use App\Services\AI\ForecastService;
use App\Services\AI\BaselineComparisonService;
use App\Services\AI\ProjectAssessmentService;
use App\Services\AI\CompletionEstimationService;
use App\Services\AI\CompletionComparisonService;
use App\Services\AI\ForecastReliabilityService;
use App\Services\AI\HistoricalSimilarityService;
use App\Models\PredictionHistory;
use Carbon\Carbon;

/**
 * ============================================================================
 * AIPredictionService
 * ============================================================================
 *
 * Purpose
 * -------
 * Acts as the bridge between the Regression Engine and the Project Monitoring
 * System.
 *
 * This service does NOT perform regression mathematics.
 * Instead, it coordinates the regression workflow and converts the results
 * into a structured Prediction Report.
 *
 * Current Phase
 * -------------
 * Phase 3.1 - Prediction Pipeline
 *
 * Current Responsibilities
 * ------------------------
 * • Build regression dataset
 * • Execute regression analysis
 * • Determine current Project Month Index
 * • Predict the next month's cumulative progress
 * • Return a Prediction Report
 *
 * Future Responsibilities
 * -----------------------
 * • Predict completion percentage
 * • Predict completion month
 * • Compare against Effective Baseline
 * • Calculate variance
 * • Determine risk level
 * • Generate recommendations
 *
 * Used By
 * -------
 * ProjectDetailService (Phase 4)
 */
class AIPredictionService
{
    /**
     * ------------------------------------------------------------------------
     * R² Improvement Threshold
     * ------------------------------------------------------------------------
     *
     * Polynomial regression is selected only when its R² exceeds SLR R²
     * by at least this amount. Prevents over-selection when both models
     * produce nearly identical fits.
     */
    private const SLR_POLYNOMIAL_R2_THRESHOLD = 0.02;

    /**
     * ------------------------------------------------------------------------
     * Regression Dataset Builder
     * ------------------------------------------------------------------------
     *
     * Responsible for preparing normalized regression datasets.
     */
    private RegressionDatasetBuilder $datasetBuilder;

    /**
     * ------------------------------------------------------------------------
     * Linear Regression Service
     * ------------------------------------------------------------------------
     *
     * Responsible only for mathematical regression calculations.
     */
    private LinearRegressionService $regressionService;

    /**
     * ------------------------------------------------------------------------
     * Polynomial Regression Service
     * ------------------------------------------------------------------------
     *
     * Supports non-linear S-curve patterns via degree-3 polynomial.
     * Auto-selected when fit is significantly better than SLR.
     */
    private PolynomialRegressionService $polynomialService;

    /**
     * ------------------------------------------------------------------------
     * Forecast Service
     * ------------------------------------------------------------------------
     *
     * Generates future project forecasts using the regression model.
     */
    private ForecastService $forecastService;

    /**
     * ------------------------------------------------------------------------
     * Baseline Comparison Service
     * ------------------------------------------------------------------------
     *
     * Compares forecasted progress against the Effective Baseline.
     */
    private BaselineComparisonService $comparisonService;

    /**
     * ------------------------------------------------------------------------
     * Project Assessment Service
     * ------------------------------------------------------------------------
     *
     * Interprets comparison results into business-friendly assessments.
     */
    private ProjectAssessmentService $assessmentService;

    /**
     * ------------------------------------------------------------------------
     * Completion Estimation Service
     * ------------------------------------------------------------------------
     *
     * Estimates when a project will reach 100% cumulative progress.
     * Uses polynomial or SLR equation to solve for the completion month.
     */
    private ?CompletionEstimationService $completionService = null;

    /**
     * ------------------------------------------------------------------------
     * Completion Comparison Service
     * ------------------------------------------------------------------------
     *
     * Orchestrates all three completion estimation methods
     * and produces a comparison summary.
     */
    private ?CompletionComparisonService $completionComparisonService = null;

    private HistoricalSimilarityService $historicalSimilarityService;

    /**
     * ------------------------------------------------------------------------
     * Forecast Reliability Service (Phase 14)
     * ------------------------------------------------------------------------
     *
     * Computes the weighted, explainable reliability score (0-100) and
     * level (High/Medium/Low) for next-month and completion forecasts.
     */
    private ForecastReliabilityService $reliabilityService;

    /**
     * ------------------------------------------------------------------------
     * Constructor
     * ------------------------------------------------------------------------
     *
     * Inject regression-related services.
     */
    
    public function __construct(
        RegressionDatasetBuilder $datasetBuilder,
        LinearRegressionService $regressionService,
        PolynomialRegressionService $polynomialService,
        ForecastService $forecastService,
        BaselineComparisonService $comparisonService,
        ProjectAssessmentService $assessmentService,
        CompletionEstimationService $completionService,
        CompletionComparisonService $completionComparisonService,
        HistoricalSimilarityService $historicalSimilarityService,
        ForecastReliabilityService $reliabilityService
    ) {
        $this->datasetBuilder = $datasetBuilder;
        $this->regressionService = $regressionService;
        $this->polynomialService = $polynomialService;
        $this->forecastService = $forecastService;
        $this->comparisonService = $comparisonService;
        $this->assessmentService = $assessmentService;
        $this->completionService = $completionService;
        $this->completionComparisonService = $completionComparisonService;
        $this->historicalSimilarityService = $historicalSimilarityService;
        $this->reliabilityService = $reliabilityService;
    }

    /**
     * ------------------------------------------------------------------------
     * Generate Prediction Report
     * ------------------------------------------------------------------------
     *
     * Entry point of the AI Prediction Service.
     *
     * Workflow
     * --------
     * Project
     *      ↓
     * Build Dataset
     *      ↓
     * Run Regression
     *      ↓
     * Predict Next Month
     *      ↓
     * Forecast Remaining Baseline
     *      ↓
     * Compare Forecast vs Baseline
     *      ↓
     * Assess Project Risk
     *      ↓
     * Build Prediction Report
     *
     * Used By
     * -------
     * ProjectDetailService
     */
    public function generatePrediction(Project $project): array
    {
        $dataset = $this->buildDataset($project);

        // Attempt cross-project supplement/fallback when data is sparse
        $crossProjectOverride = $this->applyCrossProjectData($project, $dataset);

        if ($crossProjectOverride !== null) {
            $dataset = $crossProjectOverride;
        }

        /*
        |--------------------------------------------------------------------------
        | Edge Case: Insufficient Data
        |--------------------------------------------------------------------------
        |
        | Linear regression requires at least two observations.
        | When insufficient data exists, return a minimal prediction report
        | without regression analysis, forecast, or assessment.
        |
        */

        if ($dataset['statistics']['observation_count'] < 2) {

            $currentProgress = empty($dataset['y'])
                ? 0
                : end($dataset['y']);

            return $this->buildMinimalReport(
                $currentProgress,
                $dataset
            );
        }

        $analysis = $this->runRegression($dataset);

        // Pass synthetic points count through analysis so completion estimation
        // methods can compute real-only observation count for confidence.
        // Read from the dataset top-level key (set by HistoricalSimilarityService
        // in supplement/fallback mode); statistics.synthetic_points_count is
        // never populated (Phase 8 findings, Phase 14 plan §13.5).
        $analysis['synthetic_points_count'] = $dataset['synthetic_points_count'] ?? 0;

        $currentMonthIndex =
            $dataset['statistics']['current_month_index'];

        // Latest actual progress is the monotonic floor for all short-term
        // predictions and forecasts (Fix H). In hybrid mode the merged X is
        // monotonic 1..P with synthetic fill, so the REAL current value is the
        // one at current_month_index — not end($dataset['y']), which would be
        // the last synthetic point of the forecast tail.
        $currentProgress = empty($dataset['y'])
            ? 0
            : ($dataset['y'][max(0, $currentMonthIndex - 1)] ?? end($dataset['y']));

        $predictedNextProgress =
            $this->predictNextMonth(
                $analysis,
                $currentMonthIndex,
                $currentProgress
            );

        // Phase 14 Step 1: next month is reported as a band (point ± margin).
        $nextPredictionBand = $this->computeNextMonthBand(
            $project,
            $dataset,
            $analysis,
            $predictedNextProgress
        );

        $forecast =
            $this->forecastService->generateFutureForecast(
                $project,
                $analysis,
                $currentMonthIndex,
                $currentProgress
            );

        $comparison =
            $this->comparisonService->compare(
                $forecast
            );

        $assessment =
            $this->assessmentService->assess(
                $comparison
            );

        $completion = $this->estimateCompletion(
            $project,
            $analysis,
            $currentMonthIndex,
            $currentProgress
        );

        $completionMulti = $this->estimateAllCompletions(
            $project,
            $analysis,
            $currentMonthIndex,
            $currentProgress
        );

        // Phase 14 Step 3: forecast reliability (weighted evidence score).
        // Next-month: horizon = 1, agreement neutral. Completion: horizon from
        // the completion index, agreement from the multi-method comparison.
        $reliability = $this->buildReliabilityBlock(
            $project,
            $analysis,
            $dataset,
            $currentMonthIndex,
            $completion,
            $completionMulti
        );

        $report = $this->buildPredictionReport(
            $dataset,
            $analysis,
            $currentMonthIndex,
            $predictedNextProgress,
            $forecast,
            $assessment,
            $completion,
            $completionMulti,
            $nextPredictionBand,
            $reliability
        );

        $this->savePredictionHistory(
            $project,
            $analysis,
            $currentProgress,
            $predictedNextProgress,
            $currentMonthIndex,
            $nextPredictionBand,
            $dataset['data_source'] ?? 'project_history'
        );

        return $report;
    }

    /**
     * ------------------------------------------------------------------------
     * Estimate Completion
     * ------------------------------------------------------------------------
     *
     * Uses CompletionEstimationService to estimate when the project
     * will reach 100% cumulative progress. Returns null if estimation
     * is not possible (insufficient data, low R², or already complete).
     */
    private function estimateCompletion(
        Project $project,
        array $analysis,
        int $currentMonthIndex,
        float $currentProgress
    ): ?array {

        if ($this->completionService === null) {
            return null;
        }

        if (empty($project->planned_end_date)) {
            return null;
        }

        return $this->completionService->estimate(
            $project->planned_end_date,
            $analysis,
            $currentMonthIndex,
            $project->baselineSchedules,
            $currentProgress
        );
    }

    
    /**
     * ------------------------------------------------------------------------
     * Estimate All Completions (Multi-Method)
     * ------------------------------------------------------------------------
     *
     * Runs all three completion estimation methods via
     * CompletionComparisonService and returns a combined result
     * for the completion_multi report section.
     *
     * Returns null when the comparison service is unavailable
     * or planned_end_date is missing.
     */
    private function estimateAllCompletions(
        Project $project,
        array $analysis,
        int $currentMonthIndex,
        float $currentProgress
    ): ?array {

        if ($this->completionComparisonService === null) {
            return null;
        }

        if (empty($project->planned_end_date)) {
            return null;
        }

        return $this->completionComparisonService->estimateAll(
            $project->planned_end_date,
            $analysis,
            $currentMonthIndex,
            $currentProgress,
            $project->baselineSchedules
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Build Forecast Reliability Block (Phase 14 Step 3)
     * ------------------------------------------------------------------------
     *
     * Assembles the reliability context for the next-month prediction and the
     * completion estimate and computes a weighted, explainable reliability
     * score via ForecastReliabilityService.
     *
     * Real observation count = observation_count − synthetic_points_count.
     * Per the Phase 8 findings, synthetic count is read from the dataset's
     * top-level 'synthetic_points_count' (what the presenter-ready regression
     * block exposes), NOT statistics.synthetic_points_count (never set).
     *
     * Returns null (guarded N/A) when completion is missing so the caller can
     * present a plain N/A for the completion reliability card.
     */
    private function buildReliabilityBlock(
        Project $project,
        array $analysis,
        array $dataset,
        int $currentMonthIndex,
        ?array $completion,
        ?array $completionMulti
    ): array {

        $observationCount = (int) ($dataset['statistics']['observation_count'] ?? 0);
        $syntheticCount = (int) ($dataset['synthetic_points_count'] ?? 0);
        $realObsCount = max(0, $observationCount - $syntheticCount);

        $predictionHistory = PredictionHistory::where('project_id', $project->id)
            ->whereNotNull('actual_next')
            ->get(['predicted_next', 'actual_next', 'predicted_low', 'predicted_high'])
            ->map(function ($row) {
                return [
                    'predicted_next' => $row->predicted_next,
                    'actual_next'    => $row->actual_next,
                    'predicted_low'  => $row->predicted_low,
                    'predicted_high' => $row->predicted_high,
                ];
            })
            ->all();

        $nextPrediction = $this->reliabilityService->computeForNextPrediction([
            'r_squared'          => $analysis['r_squared'] ?? 0,
            'real_obs_count'     => $realObsCount,
            'prediction_history' => $predictionHistory,
        ]);

        $completionReliability = null;

        if ($completion !== null
            && isset($completion['estimated_completion_index'])
            && $completion['estimated_completion_index'] !== null
        ) {
            $comparison = $completionMulti['comparison'] ?? [];

            $completionReliability = $this->reliabilityService->computeForCompletion([
                'r_squared'          => $analysis['r_squared'] ?? 0,
                'real_obs_count'     => $realObsCount,
                'prediction_history' => $predictionHistory,
                'estimated_index'    => $completion['estimated_completion_index'],
                'current_index'      => $currentMonthIndex,
                'agreement'          => $comparison['agreement'] ?? 'N/A',
                'valid_count'        => (int) ($comparison['valid_count'] ?? 0),
                'spread_days'        => (int) ($comparison['spread_days'] ?? 0),
            ]);
        }

        return [
            'next_prediction'   => $nextPrediction,
            'completion'        => $completionReliability,
            'fit_quality_label' => 'Curve Fit Quality',
        ];
    }

    // Attempt to supplement or replace dataset with cross-project historical data
    private function applyCrossProjectData(Project $project, array $dataset): ?array
    {
        $observationCount = $dataset['statistics']['observation_count'] ?? 0;
        $mode = $this->historicalSimilarityService->shouldSupplement($project, $observationCount);

        if ($mode === 'none') {
            return null;
        }

        if ($mode === 'fallback') {
            $fallback = $this->historicalSimilarityService->generateFallbackDataset($project);

            if ($fallback === null) {
                return null;
            }

            return $fallback;
        }

        // Mode: supplement
        $supplement = $this->historicalSimilarityService->generateSupplementDataset($project, $dataset);

        if ($supplement === null) {
            return null;
        }

        return $supplement;
    }

    /**
     * ------------------------------------------------------------------------
     * Build Regression Dataset
     * ------------------------------------------------------------------------
     *
     * Retrieves the normalized regression dataset.
     *
     * Uses
     * ----
     * RegressionDatasetBuilder
     */
    private function buildDataset(Project $project): array
    {
        return $this->datasetBuilder->buildDataset($project);
    }

    /**
     * ------------------------------------------------------------------------
     * Execute Regression Analysis
     * ------------------------------------------------------------------------
     *
     * Runs SLR for all datasets.
     * Runs polynomial regression when observation count ≥ 4.
     * Selects the best model based on R² comparison.
     */
    private function runRegression(array $dataset): array
    {
        $observationCount =
            $dataset['statistics']['observation_count'];

        $slrAnalysis = $this->regressionService->analyzeDataset(
            $dataset['x'],
            $dataset['y']
        );

        $slrR2 = $slrAnalysis['r_squared'];

        if ($observationCount < 4) {

            $slrAnalysis['regression_method'] = 'slr';

            $slrAnalysis['method_reason'] =
                'Insufficient observations for polynomial'
                . ' regression (need ≥ 4).';

            $slrAnalysis['r_squared_slr'] = $slrR2;

            $slrAnalysis['r_squared_poly'] = null;

            return $slrAnalysis;
        }

        try {

            $polyAnalysis =
                $this->polynomialService->analyzeDataset(
                    $dataset['x'],
                    $dataset['y']
                );

        } catch (\RuntimeException $e) {

            $slrAnalysis['regression_method'] = 'slr';

            $slrAnalysis['method_reason'] =
                'Polynomial regression failed; fell back to SLR.';

            $slrAnalysis['r_squared_slr'] = $slrR2;

            $slrAnalysis['r_squared_poly'] = null;

            return $slrAnalysis;
        }

        $polyR2 = $polyAnalysis['r_squared'];

        $improvement = $polyR2 - $slrR2;

        if (
            $polyR2 > $slrR2 + self::SLR_POLYNOMIAL_R2_THRESHOLD
        ) {

            $polyAnalysis['regression_method'] = 'polynomial';

            $polyAnalysis['method_reason'] = sprintf(
                'Polynomial regression selected'
                . ' (R² = %.4f vs %.4f for SLR'
                . ' — improvement of %.4f).',
                $polyR2,
                $slrR2,
                $improvement
            );

            $polyAnalysis['r_squared_slr'] = $slrR2;

            $polyAnalysis['r_squared_poly'] = $polyR2;

            return $polyAnalysis;
        }

        $slrAnalysis['regression_method'] = 'slr';

        $slrAnalysis['method_reason'] =
            'Simple linear regression selected'
            . ' (fit is comparable or better than polynomial).';

        $slrAnalysis['r_squared_slr'] = $slrR2;

        $slrAnalysis['r_squared_poly'] = $polyR2;

        return $slrAnalysis;
    }

    /**
     * ------------------------------------------------------------------------
     * Predict Next Project Month
     * ------------------------------------------------------------------------
     *
     * Uses the regression model that was selected during analysis
     * (SLR or polynomial).
     *
     * Example
     *
     * Current Month Index
     *      10
     *
     * Next Month Index
     *      11
     */
    private function predictNextMonth(
        array $analysis,
        int $currentMonthIndex,
        float $currentProgress
    ): float {

        $method = $analysis['regression_method'] ?? 'slr';

        if ($method === 'polynomial') {

            $predicted =
                $this->polynomialService->predictUsingAnalysis(
                    $analysis,
                    $currentMonthIndex + 1
                );

        } else {

            $predicted =
                $this->regressionService->predictUsingAnalysis(
                    $analysis,
                    $currentMonthIndex + 1
                );
        }

        // Fix H: clamp is monotonic — next month can never be reported at a
        // lower value than the latest actual progress.
        return max($currentProgress, min(100, $predicted));
    }

    /**
     * ------------------------------------------------------------------------
     * Next-Month Prediction Band (Phase 14 Step 1)
     * ------------------------------------------------------------------------
     *
     * The next-month prediction is reported as a band, never a single point
     * (Decision 6): low = point − margin, high = point + margin.
     *
     * margin = max( fitResidualRMSE, backtestAvgAbsError, 2.0 )   [%]
     *   • fitResidualRMSE      — RMSE of the selected model's residuals over
     *                            the observed months (pure fit math).
     *   • backtestAvgAbsError  — mean |actual − predicted| over fulfilled
     *                            prediction_history rows (0 when none).
     *   • floor 2.0%           — short/new projects never show a tiny band.
     *
     * Both low and high are clamped to [0, 100].
     */
    private function computeNextMonthBand(
        Project $project,
        array $dataset,
        array $analysis,
        float $predictedNextProgress
    ): array {
        $margin = max(
            $this->fitResidualRMSE($dataset, $analysis),
            $this->backtestAvgAbsError($project),
            2.0
        );

        return [
            'progress'       => round($predictedNextProgress, 2),
            'progress_low'   => round(max(0, $predictedNextProgress - $margin), 2),
            'progress_high'  => round(min(100, $predictedNextProgress + $margin), 2),
            'margin'         => round($margin, 2),
        ];
    }

    // RMSE of the selected regression model's residuals over observed months.
    private function fitResidualRMSE(array $dataset, array $analysis): float
    {
        $x = $dataset['x'] ?? [];
        $y = $dataset['y'] ?? [];

        $count = count($x);

        if ($count === 0) {
            return 0.0;
        }

        $method = $analysis['regression_method'] ?? 'slr';

        $sumSquares = 0.0;

        foreach ($x as $i => $xValue) {
            $predicted = $method === 'polynomial'
                ? $this->polynomialService->predictUsingAnalysis($analysis, $xValue)
                : $this->regressionService->predictUsingAnalysis($analysis, $xValue);

            $residual = $y[$i] - $predicted;

            $sumSquares += $residual * $residual;
        }

        return sqrt($sumSquares / $count);
    }

    // Mean |actual − predicted| over fulfilled prediction_history rows
    // (rows whose predicted month has a recorded actual). 0 when none.
    private function backtestAvgAbsError(Project $project): float
    {
        $fulfilled = PredictionHistory::where('project_id', $project->id)
            ->whereNotNull('actual_next')
            ->get(['predicted_next', 'actual_next']);

        if ($fulfilled->isEmpty()) {
            return 0.0;
        }

        $totalError = $fulfilled->reduce(
            fn (float $carry, $row) => $carry + abs($row->actual_next - $row->predicted_next),
            0.0
        );

        return $totalError / $fulfilled->count();
    }

    /**
     * ------------------------------------------------------------------------
     * Prediction Accuracy By Data Source (C7)
     * ------------------------------------------------------------------------
     *
     * Compares forecast error rates across the modes that produced each
     * prediction: project_history (own data), hybrid (own + similar),
     * similar_projects (borrowed only). This is the empirical evidence for
     * tuning similarity weights / thresholds and the Phase 2 blend weight k —
     * predictions are logged with their data_source at save time.
     *
     * Counts only fulfilled rows (predicted month has a recorded actual).
     * 'accurate' mirrors the Phase 7 convention: |actual − predicted| < 2.
     *
     * Returns an array keyed by data_source:
     *   {
     *     'project_history' => [
     *         'count'            => int,
     *         'accurate_count'   => int,
     *         'accuracy_percent' => float|null,   // null when count = 0
     *         'avg_abs_error'    => float|null,   // null when count = 0
     *         'avg_variance'     => float|null,   // mean actual − predicted
     *     ], ...}
     */
    public function predictionAccuracyByDataSource(): array
    {
        $rows = PredictionHistory::whereNotNull('actual_next')
            ->get(['data_source', 'predicted_next', 'actual_next']);

        return $rows->groupBy(fn ($row) => $row->data_source ?? 'project_history')
            ->map(function ($group) {
                $count = $group->count();

                if ($count === 0) {
                    return [
                        'count'            => 0,
                        'accurate_count'   => 0,
                        'accuracy_percent' => null,
                        'avg_abs_error'    => null,
                        'avg_variance'     => null,
                    ];
                }

                $absErrorSum = $group->sum(
                    fn ($row) => abs((float) $row->actual_next - (float) $row->predicted_next)
                );
                $varianceSum = $group->sum(
                    fn ($row) => (float) $row->actual_next - (float) $row->predicted_next
                );
                $accurate = $group->filter(
                    fn ($row) => abs((float) $row->actual_next - (float) $row->predicted_next) < 2
                )->count();

                return [
                    'count'            => $count,
                    'accurate_count'   => $accurate,
                    'accuracy_percent' => round($accurate / $count * 100, 1),
                    'avg_abs_error'    => round($absErrorSum / $count, 2),
                    'avg_variance'     => round($varianceSum / $count, 2),
                ];
            })
            ->all();
    }

    /**
     * ------------------------------------------------------------------------
     * Save Prediction History
     * ------------------------------------------------------------------------
     *
     * Saves the current prediction to the prediction_history table for
     * later accuracy tracking (rev 3 — keep-latest).
     *
     * One prediction row per (project, predicted month). A pending row is
     * refreshed IN PLACE with the newest forecast whenever the data changes;
     * identical re-generation (page re-view / repeated call) is skipped. This
     * keeps the history equal to the forecast the user last saw and avoids
     * spurious rows from data-entry corrections.
     *
     * No row is created or refreshed once the predicted month is fulfilled
     * (a row for it already has actual_next) — history never records a
     * forecast made after the outcome is known.
     *
     * Called by: generatePrediction()
     * Calls: PredictionHistory::first(), PredictionHistory::create()
     */
    private function savePredictionHistory(
        Project $project,
        array $analysis,
        float $currentProgress,
        float $predictedNextProgress,
        int $currentMonthIndex,
        ?array $nextPredictionBand = null,
        string $dataSource = 'project_history'
    ): void {

        if ($currentProgress >= 100) {
            return;
        }

        // Resolve the PREDICTED month's baseline schedule (the month this
        // prediction is FOR). Capture both its id and month_label so the row
        // can be matched precisely when backfilling actual_next.
        $baseline = ProjectBaselineSchedule::where('project_id', $project->id)
            ->orderBy('planned_date')
            ->offset($currentMonthIndex)
            ->limit(1)
            ->first(['id', 'month_label']);

        $monthLabel = $baseline->month_label ?? null;
        $baselineScheduleId = $baseline->id ?? null;

        // Fallback: predicted month may be beyond the last baseline schedule
        if (!$monthLabel) {
            $last = ProjectBaselineSchedule::where('project_id', $project->id)
                ->orderBy('planned_date', 'desc')
                ->first(['month_label']);
            if ($last) {
                $monthLabel = Carbon::parse($last->month_label . '-01')
                    ->addMonth()
                    ->format('Y-m');
            }
        }

        // Fulfilled-month guard: once the predicted month's outcome is known,
        // never create a new forecast row for it (prevents hindsight rows
        // after the actual is recorded).
        if ($monthLabel) {
            $fulfilled = PredictionHistory::where('project_id', $project->id)
                ->where('month_label', $monthLabel)
                ->whereNotNull('actual_next')
                ->exists();

            if ($fulfilled) {
                return;
            }
        }

        // Keep-latest: locate the existing row for (project, predicted month).
        // If present, refresh it in place with the newest forecast when the
        // data state changes; identical re-generation is skipped. If absent,
        // create the month's first row.
        $existingQuery = PredictionHistory::where('project_id', $project->id);

        if ($monthLabel) {
            $existingQuery->where('month_label', $monthLabel);
        } else {
            $existingQuery->whereNull('month_label');
        }

        $existing = $existingQuery->orderByDesc('id')->first();

        if ($existing) {
            if (
                round($existing->current_progress, 2) === round($currentProgress, 2)
                && round($existing->predicted_next, 2) === round($predictedNextProgress, 2)
            ) {
                return;
            }

            $existing->update([
                'regression_method'   => $analysis['regression_method'] ?? 'slr',
                'data_source'         => $dataSource,
                'current_progress'    => round($currentProgress, 2),
                'predicted_next'      => round($predictedNextProgress, 2),
                'predicted_low'       => $nextPredictionBand['progress_low'] ?? null,
                'predicted_high'      => $nextPredictionBand['progress_high'] ?? null,
                'r_squared'           => $analysis['r_squared'] ?? 0,
                'regression_snapshot' => $analysis,
            ]);

            return;
        }

        PredictionHistory::create([
            'project_id'           => $project->id,
            'month_label'          => $monthLabel,
            'baseline_schedule_id' => $baselineScheduleId,
            'regression_method'    => $analysis['regression_method'] ?? 'slr',
            'data_source'          => $dataSource,
            'current_progress'     => round($currentProgress, 2),
            'predicted_next'       => round($predictedNextProgress, 2),
            'predicted_low'        => $nextPredictionBand['progress_low'] ?? null,
            'predicted_high'       => $nextPredictionBand['progress_high'] ?? null,
            'r_squared'            => $analysis['r_squared'] ?? 0,
            'regression_snapshot'  => $analysis,
        ]);
    }

   /**
     * ------------------------------------------------------------------------
     * Build Prediction Report
     * ------------------------------------------------------------------------
     *
     * Combines all AI prediction outputs into a structured Prediction Report.
     *
     * The report is intentionally divided into logical sections so future
     * forecasting and business analysis can be added without restructuring
     * the response format.
     *
     * Used By
     * -------
     * generatePrediction()
     */
    private function buildPredictionReport(
        array $dataset,
        array $analysis,
        int $currentMonthIndex,
        float $predictedNextProgress,
        array $forecast,
        array $assessment,
        ?array $completion = null,
        ?array $completionMulti = null,
        ?array $nextPredictionBand = null,
        ?array $reliability = null
    ): array {

        /*
        |--------------------------------------------------------------------------
        | Current Project Progress
        |--------------------------------------------------------------------------
        |
        | The regression dataset is already normalized and sorted
        | chronologically. In hybrid mode the merged X is monotonic 1..P with
        | synthetic fill, so the REAL current value is the one at
        | current_month_index — not end(), which would be the last synthetic
        | point of the forecast tail.
        |
        */

        $currentProgress = empty($dataset['y'])
            ? 0
            : ($dataset['y'][max(0, $currentMonthIndex - 1)] ?? end($dataset['y']));

        return [

            /*
            |--------------------------------------------------------------------------
            | Current Project State
            |--------------------------------------------------------------------------
            */

            'current_state' => [

                'progress' => $currentProgress,

                'month_index' => $currentMonthIndex,

            ],

            /*
            |--------------------------------------------------------------------------
            | Next Month Prediction
            |--------------------------------------------------------------------------
            */

            'next_prediction' => [

                'progress' => round(
                    $predictedNextProgress,
                    2
                ),

                // Phase 14 Step 1: interval band (never a single point).
                'progress_low' => $nextPredictionBand['progress_low']
                    ?? round(max(0, $predictedNextProgress - 2.0), 2),

                'progress_high' => $nextPredictionBand['progress_high']
                    ?? round(min(100, $predictedNextProgress + 2.0), 2),

                'margin' => $nextPredictionBand['margin'] ?? 2.0,

            ],

            /*
            |--------------------------------------------------------------------------
            | Future Forecast
            |--------------------------------------------------------------------------
            */

            'forecast' => $forecast,

            /*
            |--------------------------------------------------------------------------
            | Project Assessment
            |--------------------------------------------------------------------------
            */

            'assessment' => $assessment,

            /*
            |--------------------------------------------------------------------------
            | Regression Analysis
            |--------------------------------------------------------------------------
            */

            'regression' => $this->enrichRegressionWithCrossProjectMeta($analysis, $dataset),

            /*
            |--------------------------------------------------------------------------
            | Completion Estimation
            |--------------------------------------------------------------------------
            */

            'completion' => $completion ?? [
                'estimated_completion_index' => null,
                'estimated_completion_date'  => null,
                'predicted_delay_days'       => 0,
                'completion_confidence'      => null,
            ],

            /*
            |--------------------------------------------------------------------------
            | Multi-Method Completion Estimation (Phase 3.5)
            |--------------------------------------------------------------------------
            |
            | Contains results from all three methods (Polynomial, Schedule-Aware,
            | Earned Schedule EAC_time) plus a comparison summary with earliest/
            | latest dates, spread in days, and agreement level.
            |
            */

            'completion_multi' => $completionMulti ?? [
                'methods'    => [
                    'polynomial'      => null,
                    'schedule_aware'  => null,
                    'earned_schedule' => null,
                ],
                'comparison' => [
                    'earliest_date' => null,
                    'latest_date'   => null,
                    'spread_days'   => 0,
                    'agreement'     => 'N/A',
                    'valid_count'   => 0,
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Forecast Reliability (Phase 14)
            |--------------------------------------------------------------------------
            |
            | Weighted, explainable reliability score for the next-month
            | prediction and the completion estimate (or null when no
            | completion exists). R² is relabeled as curve-fit quality only.
            |
            */

            'reliability' => $reliability ?? [
                'next_prediction'   => null,
                'completion'        => null,
                'fit_quality_label' => 'Curve Fit Quality',
            ],

            /*
            |--------------------------------------------------------------------------
            | Dataset Statistics
            |--------------------------------------------------------------------------
            */

            'statistics' => $dataset['statistics'],

        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Build Minimal Prediction Report
     * ------------------------------------------------------------------------
     *
     * Returns a prediction report with only basic state information when
     * there is insufficient data for regression analysis.
     *
     * Used By
     * -------
     * generatePrediction()
     */
    private function buildMinimalReport(
        float $currentProgress,
        array $dataset
    ): array {

        $monthIndex = $dataset['statistics']['current_month_index'];

        return [

            'current_state' => [

                'progress' => $currentProgress,

                'month_index' => $monthIndex,

            ],

            'next_prediction' => [

                'progress' => 0.0,

            ],

            'forecast' => [],

            'assessment' => [],

            'regression' => $this->enrichRegressionWithCrossProjectMeta([
                'observation_count' => $dataset['statistics']['observation_count'],

                'mean_x'            => 0.0,

                'mean_y'            => 0.0,

                'slope'             => 0.0,

                'intercept'         => 0.0,

                'equation'          => 'y = 0.0000x + 0.0000',

                'r_squared'         => 0.0,

                'fit_quality'       => 'N/A',

                'regression_method' => 'slr',

                'method_reason'     => 'Insufficient data for regression analysis.',

                'r_squared_slr'     => null,

                'r_squared_poly'    => null,

            ], $dataset),

            'completion' => [
                'estimated_completion_index' => null,
                'estimated_completion_date'  => null,
                'predicted_delay_days'       => 0,
                'completion_confidence'      => null,
            ],

            'completion_multi' => [
                'methods'    => [
                    'polynomial'      => null,
                    'schedule_aware'  => null,
                    'earned_schedule' => null,
                ],
                'comparison' => [
                    'earliest_date' => null,
                    'latest_date'   => null,
                    'spread_days'   => 0,
                    'agreement'     => 'N/A',
                    'valid_count'   => 0,
                ],
            ],

            'reliability' => [
                'next_prediction'   => null,
                'completion'        => null,
                'fit_quality_label' => 'Curve Fit Quality',
            ],

            'statistics' => $dataset['statistics'],

        ];
    }

    // Merge cross-project metadata into the regression block if present
    private function enrichRegressionWithCrossProjectMeta(array $regression, array $dataset): array
    {
        if (isset($dataset['data_source'])) {
            $regression['data_source'] = $dataset['data_source'];
        }

        if (isset($dataset['similar_projects_count'])) {
            $regression['similar_projects_count'] = $dataset['similar_projects_count'];
        }

        if (isset($dataset['similar_projects_details'])) {
            $regression['similar_projects_details'] = $dataset['similar_projects_details'];
        }

        if (isset($dataset['synthetic_points_count'])) {
            $regression['synthetic_points_count'] = $dataset['synthetic_points_count'];
        }

        if (isset($regression['data_source']) && $regression['data_source'] !== 'project_history') {
            $count = $regression['similar_projects_count'] ?? 0;
            if ($regression['data_source'] === 'similar_projects') {
                $regression['similar_projects_disclaimer'] =
                    'Prediction based entirely on ' . $count
                    . ' similar completed project(s) in this category.';
            } elseif ($regression['data_source'] === 'hybrid') {
                $synthCount = $regression['synthetic_points_count'] ?? 0;
                $regression['similar_projects_disclaimer'] =
                    'Prediction supplemented with data from ' . $count
                    . ' similar completed project(s) (' . $synthCount . ' additional data point(s)).';
            }
        }

        return $regression;
    }
}

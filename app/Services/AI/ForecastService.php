<?php

namespace App\Services\AI;

use App\Models\Project;
use App\Services\AI\Regression\LinearRegressionService;
use App\Services\AI\Regression\PolynomialRegressionService;

/**
 * ============================================================================
 * ForecastService
 * ============================================================================
 *
 * Purpose
 * -------
 * Generates future project progress forecasts using the completed
 * regression analysis.
 *
 * Responsibilities
 * ----------------
 * • Generate monthly progress forecasts
 * • Use the Effective Baseline as the forecast horizon
 * • Return structured forecast data
 *
 * This service performs NO regression mathematics.
 * It only consumes regression results.
 */
class ForecastService
{
    /**
     * ------------------------------------------------------------------------
     * Linear Regression Service
     * ------------------------------------------------------------------------
     *
     * Used to calculate predicted cumulative progress for SLR.
     */
    private LinearRegressionService $regressionService;

    /**
     * ------------------------------------------------------------------------
     * Polynomial Regression Service
     * ------------------------------------------------------------------------
     *
     * Used to calculate predicted cumulative progress for polynomial.
     */
    private PolynomialRegressionService $polynomialService;

    /**
     * ------------------------------------------------------------------------
     * Constructor
     * ------------------------------------------------------------------------
     */
    public function __construct(
        LinearRegressionService $regressionService,
        PolynomialRegressionService $polynomialService
    ) {
        $this->regressionService = $regressionService;
        $this->polynomialService = $polynomialService;
    }

    /**
     * ------------------------------------------------------------------------
     * Generate Future Forecast
     * ------------------------------------------------------------------------
     *
     * Generates predicted cumulative progress for every remaining month
     * in the project's Effective Baseline.
     *
     * The Effective Baseline determines the forecast horizon while the
     * regression model estimates cumulative progress.
     *
     * Current Phase
     * -------------
     * Phase 3.2 - Forecast Generation
     */
    public function generateFutureForecast(
        Project $project,
        array $analysis,
        int $currentMonthIndex,
        float $latestActual = 0,
        ?array $blendReference = null,
        ?float $blendWeight = null
    ): array {

        /*
        |--------------------------------------------------------------------------
        | Remaining Baseline Months
        |--------------------------------------------------------------------------
        */

        $remainingBaseline = $project->baselineSchedules
            ->slice($currentMonthIndex)
            ->values();

        $forecast = [];

        // Fix H: cumulative progress is monotonic — each forecast month forms
        // a floor for the next, seeded from the latest actual progress so the
        // first forecast month can never dip below where the project already is.
        $floor = max(0, $latestActual);

        // Phase 2 (v2.6 §5.3): blend schedule. When a class reference is
        // available, progress(t) = w·own(t) + (1−w)·class(t), with the P25/P75
        // band blended the same way. w = obs/(obs+k); no reference → own model
        // only (existing behaviour, data_source project_history).
        $useBlend = $blendReference !== null
            && !empty($blendReference)
            && $blendWeight !== null
            && $blendWeight > 0;

        /*
        |--------------------------------------------------------------------------
        | Forecast Generation
        |--------------------------------------------------------------------------
        */

        foreach ($remainingBaseline as $offset => $baseline) {

            /*
            |--------------------------------------------------------------------------
            | Project Month Index
            |--------------------------------------------------------------------------
            |
            | Example
            |
            | Current Month Index : 10
            |
            | Remaining Months:
            |
            | Offset 0 -> Month 11
            | Offset 1 -> Month 12
            | Offset 2 -> Month 13
            |
            */

            $monthIndex = $currentMonthIndex + $offset + 1;

            /*
            |--------------------------------------------------------------------------
            | Select Regression Model
            |--------------------------------------------------------------------------
            |
            | Uses the same model that was selected during the
            | regression analysis phase.
            |
            */

            $method = $analysis['regression_method'] ?? 'slr';

            if ($method === 'polynomial') {

                $predictedProgress =
                    $this->polynomialService->predictUsingAnalysis(
                        $analysis,
                        $monthIndex
                    );

            } else {

                $predictedProgress =
                    $this->regressionService->predictUsingAnalysis(
                        $analysis,
                        $monthIndex
                    );
            }

            $predictedLow  = null;
            $predictedHigh = null;

            // Blend with the class reference band (Phase 2 §5.3/§5.4):
            //   point(t) = w·own(t) + (1−w)·classMedian(t)
            //   low/high = w·own(t) + (1−w)·classLower/Upper(t)
            // The monotonic floor and the 0..100 clamps still apply.
            if ($useBlend && isset($blendReference['median'][$monthIndex])) {
                $classMedian = $blendReference['median'][$monthIndex] ?? null;
                $classLower  = $blendReference['lower'][$monthIndex] ?? $classMedian;
                $classUpper  = $blendReference['upper'][$monthIndex] ?? $classMedian;

                if ($classMedian !== null) {
                    $predictedProgress = ($blendWeight * $predictedProgress)
                        + ((1 - $blendWeight) * $classMedian);

                    $predictedLow = ($blendWeight * $predictedProgress)
                        + ((1 - $blendWeight) * $classLower);

                    $predictedHigh = ($blendWeight * $predictedProgress)
                        + ((1 - $blendWeight) * $classUpper);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Regression may predict values outside the valid
            | cumulative progress range.
            |
            | Clamp predictions between 0% and 100%.
            |--------------------------------------------------------------------------
            */

            // Fix H: clamp is monotonic — progress can never decrease from
            // one forecast month to the next.
            $predictedProgress = max(
                $floor,
                min(100, $predictedProgress)
            );

            // Blend-derived P25/P75 band, clamped to [0, 100] (forecast rows
            // keep the monotonic floor for the point, but the band may dip
            // below so the uncertainty range is honest).
            $clampedLow = $predictedLow !== null
                ? round(max(0, min(100, $predictedLow)), 2)
                : null;

            $clampedHigh = $predictedHigh !== null
                ? round(max(0, min(100, $predictedHigh)), 2)
                : null;

            $forecast[] = [

                'month_index' => $monthIndex,

                'month_label' => $baseline->month_label,

                'planned_date' => $baseline->planned_date,

                'planned_progress' => (float) $baseline->planned_progress_percent,

                'predicted_progress' => round(
                    $predictedProgress,
                    2
                ),

                // Phase 2 §5.4: P25/P75 band on each forecast row when the
                // blend is active (else null — own-model rows keep the
                // single value the previous UI already handles).
                'predicted_progress_low'  => $clampedLow,
                'predicted_progress_high' => $clampedHigh,

            ];

            $floor = round($predictedProgress, 2);
        }

        return $forecast;
    }
}

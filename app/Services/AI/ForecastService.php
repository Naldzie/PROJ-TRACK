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
        int $currentMonthIndex
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

            /*
            |--------------------------------------------------------------------------
            | Regression may predict values outside the valid
            | cumulative progress range.
            |
            | Clamp predictions between 0% and 100%.
            |--------------------------------------------------------------------------
            */

            $predictedProgress = max(
                0,
                min(100, $predictedProgress)
            );

            $forecast[] = [

                'month_index' => $monthIndex,

                'month_label' => $baseline->month_label,

                'planned_date' => $baseline->planned_date,

                'planned_progress' => (float) $baseline->planned_progress_percent,

                'predicted_progress' => round(
                    $predictedProgress,
                    2
                ),

            ];
        }

        return $forecast;
    }
}
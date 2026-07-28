<?php

namespace App\Services\AI;

/**
 * ============================================================================
 * BaselineComparisonService
 * ============================================================================
 *
 * Purpose
 * -------
 * Compares forecasted project progress against the project's
 * Effective Baseline.
 *
 * Responsibilities
 *----------------
 *• Calculate forecast variance
 *• Produce comparison records for downstream AI analysis

 *This service performs NO regression calculations and NO business
 *interpretation.

 *It only compares forecasted progress against the Effective Baseline.
 *
 * This service performs NO regression calculations.
 * It only analyzes forecast results.
 */
class BaselineComparisonService
{
    /**
     * ------------------------------------------------------------------------
     * Compare Forecast Against Baseline
     * ------------------------------------------------------------------------
     *
     * Each forecast record already contains both the planned
     * progress and the predicted progress.
     *
     * This method calculates the variance between the
     * forecasted cumulative progress and the planned
     * baseline progress.
     *
     * Business interpretation is handled by
     * ProjectAssessmentService.
     */
    public function compare(array $forecast): array
    {
        $comparison = [];

        foreach ($forecast as $record) {

            $variance =
                $record['predicted_progress']
                -
                $record['planned_progress'];

            /*
            |--------------------------------------------------------------------------
            | Determine Forecast Status
            |--------------------------------------------------------------------------
            */

            $comparison[] = [

                ...$record,

                'variance' => round($variance, 2),

            ];
        }

        return $comparison;
    }
}
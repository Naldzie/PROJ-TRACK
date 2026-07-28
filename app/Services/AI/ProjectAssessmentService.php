<?php

namespace App\Services\AI;

/**
 * ============================================================================
 * ProjectAssessmentService
 * ============================================================================
 *
 * Purpose
 * -------
 * Interprets comparison results and produces project assessment
 * information for decision support.
 *
 * Current Phase
 * -------------
 * Phase 3.4 - Project Assessment
 *
 * Current Responsibilities
 * ------------------------
 * • Determine risk level
 * • Determine project status
 *
 * Future Responsibilities
 * -----------------------
 * • AI recommendations
 * • Completion confidence
 * • Delay explanation
 * • Mitigation suggestions
 *
 * This service performs NO regression calculations.
 * It only interprets comparison results.
 */
class ProjectAssessmentService
{
    /**
     * ------------------------------------------------------------------------
     * Assess Forecast Comparison
     * ------------------------------------------------------------------------
     */
    public function assess(array $comparison): array
    {
        $assessment = [];

        foreach ($comparison as $record) {

            $variance = $record['variance'];

            /*
            |--------------------------------------------------------------------------
            | Schedule Status
            |--------------------------------------------------------------------------
            */

            $status = $variance >= 0
                ? 'On Track'
                : 'Behind Schedule';

            /*
            |--------------------------------------------------------------------------
            | Risk Classification
            |--------------------------------------------------------------------------
            */

            if ($variance >= 0) {

                $risk = 'Low';

            } elseif ($variance >= -10) {

                $risk = 'Moderate';

            } elseif ($variance >= -25) {

                $risk = 'High';

            } else {

                $risk = 'Critical';

            }

            $assessment[] = [

                ...$record,

                'status' => $status,

                'risk_level' => $risk,

            ];
        }

        return $assessment;
    }
}
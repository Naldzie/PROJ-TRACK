<?php

namespace App\Services\AI;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Orchestrate all three completion estimation methods and produce a comparison summary.
// Called by: AIPredictionService::generatePrediction()
// Uses: CompletionEstimationService for all three individual solvers
// Produces: aggregated array with per-method results + comparison metadata
class CompletionComparisonService
{
    private CompletionEstimationService $completionService;

    public function __construct(CompletionEstimationService $completionService)
    {
        $this->completionService = $completionService;
    }

    // Run all three completion estimation methods and return a combined result.
    // Called by: AIPredictionService::estimateAllCompletions()
    // Returns: array with 'methods' (3 named results) and 'comparison' (summary)
    public function estimateAll(
        Carbon $plannedEndDate,
        array $analysis,
        int $currentMonthIndex,
        float $currentProgress,
        Collection $baselineSchedules,
        ?array $classReference = null
    ): array {

        $totalPlannedMonths = $baselineSchedules->count();

        // Method 2: Schedule-Aware (existing constant-lag method) — computed
        // first because the polynomial sanity fallback blends against it.
        $scheduleAware = $this->completionService->estimate(
            $plannedEndDate,
            $analysis,
            $currentMonthIndex,
            $baselineSchedules,
            $currentProgress
        );

        // Method 1: Polynomial (Newton-Raphson)
        $polynomial = $this->completionService->estimatePolynomial(
            $analysis,
            $totalPlannedMonths,
            $currentMonthIndex,
            $plannedEndDate,
            $baselineSchedules
        );

        // A2 sanity fallback: when the project's own polynomial completion is
        // implausible (past date / descending tail / absurd horizon) but the
        // polynomial WAS a selected, well-fitted model, estimate how long
        // similar projects took from currentProgress% → 100% via the aggregate
        // class curve, blended with the schedule-aware index, and use that as
        // the polynomial method's answer (flagged fallback_method).
        if ($polynomial === null
            && $this->completionService->isPolynomialSelected($analysis)
            && !empty($classReference)
        ) {
            $scheduleAwareIndex = $scheduleAware !== null
                ? (float) $scheduleAware['estimated_completion_index']
                : null;

            $polynomial = $this->completionService->estimateFromClassReference(
                $classReference,
                $currentProgress,
                $currentMonthIndex,
                $totalPlannedMonths,
                $plannedEndDate,
                $baselineSchedules,
                $scheduleAwareIndex,
                (float) ($analysis['r_squared'] ?? 0.0),
                (int) ($analysis['observation_count'] ?? 0),
                (int) ($analysis['synthetic_points_count'] ?? 0)
            );
        }

        // Method 3: Earned Schedule EAC_time (standard ES formula)
        $earnedSchedule = $this->completionService->estimateEarnedSchedule(
            $plannedEndDate,
            $currentMonthIndex,
            $currentProgress,
            $baselineSchedules,
            $analysis
        );

        $methods = [
            'polynomial' => $polynomial ? $polynomial + [
                'label' => 'Polynomial (Trend-Based)',
                'label_short' => 'Polynomial',
            ] : null,
            'schedule_aware' => $scheduleAware ? $scheduleAware + [
                'label' => 'Schedule-Aware (Conservative)',
                'label_short' => 'Schedule-Aware',
            ] : null,
            'earned_schedule' => $earnedSchedule ? $earnedSchedule + [
                'label' => 'Earned Schedule EAC_time',
                'label_short' => 'EAC_time',
            ] : null,
        ];

        $comparison = $this->computeComparison($methods);

        return [
            'methods' => $methods,
            'comparison' => $comparison,
        ];
    }

    // Compute comparison summary across all valid completion estimates.
    // Called by: estimateAll()
    // Determines earliest/latest dates, spread in days, and agreement level
    private function computeComparison(array $methods): array
    {
        $dates = [];

        foreach ($methods as $method) {
            if ($method && isset($method['estimated_completion_date'])) {
                $dates[] = $method['estimated_completion_date'];
            }
        }

        $validCount = count($dates);

        if ($validCount === 0) {
            return [
                'earliest_date' => null,
                'latest_date' => null,
                'spread_days' => 0,
                'agreement' => 'N/A',
                'valid_count' => 0,
            ];
        }

        $earliest = min($dates);
        $latest = max($dates);

        $spreadDays = (int) round($earliest->diffInDays($latest));

        $agreementConfig = config('ai.completion.agreement');

        $agreement = match (true) {
            $validCount < 2 => 'N/A',
            $spreadDays <= $agreementConfig['high_days'] => 'High',
            $spreadDays <= $agreementConfig['moderate_days'] => 'Moderate',
            default => 'Low',
        };

        return [
            'earliest_date' => $earliest,
            'latest_date' => $latest,
            'spread_days' => $spreadDays,
            'agreement' => $agreement,
            'valid_count' => $validCount,
        ];
    }
}

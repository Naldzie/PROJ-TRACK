<?php

namespace App\Services\AI;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class CompletionEstimationService
{
    private const MAX_REASONABLE_MONTHS = 1000;

    private const HIGH_CONFIDENCE_R2 = 0.85;

    private const MEDIUM_CONFIDENCE_R2 = 0.70;

    private const HIGH_CONFIDENCE_OBS = 6;

    private const MEDIUM_CONFIDENCE_OBS = 4;

    private const HIGH_CONFIDENCE_INDEX_RATIO = 1.5;

    private const LOW_CONFIDENCE_INDEX_RATIO = 2.0;

    private const MIN_R2_FOR_ESTIMATE = 0.50;

    public function estimate(
        Carbon $plannedEndDate,
        array $analysis,
        int $currentMonthIndex,
        Collection $baselineSchedules,
        float $currentProgress
    ): ?array {

        if ($currentProgress >= 100.0) {
            return null;
        }

        $rSquared = $analysis['r_squared'] ?? 0.0;

        if ($rSquared < self::MIN_R2_FOR_ESTIMATE) {
            return null;
        }

        $schedules = $baselineSchedules->values();

        if ($schedules->isEmpty()) {
            return null;
        }

        $totalPlannedMonths = $schedules->count();

        $effectivePosition = $this->findEffectivePosition(
            $currentProgress,
            $schedules
        );

        if ($effectivePosition === null) {
            return null;
        }

        $lag = $currentMonthIndex - $effectivePosition;

        $estimationIndex = $totalPlannedMonths + $lag;

        if ($estimationIndex <= $currentMonthIndex) {
            return null;
        }

        if ($estimationIndex > self::MAX_REASONABLE_MONTHS) {
            return null;
        }

        $estimatedDate = $this->indexToDate(
            $estimationIndex,
            $baselineSchedules
        );

        $absoluteDiff = (int) round(
            abs($estimatedDate->diffInDays($plannedEndDate))
        );

        $predictedDelayDays = $estimatedDate->isAfter($plannedEndDate)
            ? $absoluteDiff
            : -$absoluteDiff;

        $plannedEndIndex = max(
            $totalPlannedMonths,
            1
        );

        $completionConfidence = $this->classifyConfidence(
            $rSquared,
            $analysis['observation_count'] ?? 0,
            $estimationIndex,
            $plannedEndIndex,
            $analysis['synthetic_points_count'] ?? 0
        );

        return [
            'estimated_completion_index' => round($estimationIndex, 4),
            'estimated_completion_date'  => $estimatedDate,
            'predicted_delay_days'       => $predictedDelayDays,
            'completion_confidence'      => $completionConfidence,
        ];
    }

    // Estimate completion using Newton-Raphson cubic root solver.
    // Called by: CompletionComparisonService::estimateAll()
    // Uses: polynomial coefficients from regression analysis
    // Solves ax³ + bx² + cx + (d - 100) = 0 to find the month index where progress = 100%
    // f'(x) = 3ax² + 2bx + c
    // Returns null when polynomial is not selected, R² is too low, or solver fails
    public function estimatePolynomial(
        array $analysis,
        int $totalPlannedMonths,
        int $currentMonthIndex,
        ?Carbon $plannedEndDate = null,
        ?Collection $baselineSchedules = null
    ): ?array {

        if (($analysis['regression_method'] ?? 'slr') !== 'polynomial') {
            return null;
        }

        $rSquared = $analysis['r_squared'] ?? 0.0;

        if ($rSquared < self::MIN_R2_FOR_ESTIMATE) {
            return null;
        }

        $a = $analysis['a'] ?? 0;
        $b = $analysis['b'] ?? 0;
        $c = $analysis['c'] ?? 0;
        $d = $analysis['d'] ?? 0;

        $x = max($totalPlannedMonths + 1, 2);

        $maxIterations = 100;
        $tolerance = 0.0001;

        // Newton-Raphson iteration: x_{n+1} = x_n - f(x_n) / f'(x_n)
        for ($i = 0; $i < $maxIterations; $i++) {
            $fx = $a * $x * $x * $x + $b * $x * $x + $c * $x + ($d - 100);

            if (abs($fx) < $tolerance) {
                break;
            }

            $fpx = 3 * $a * $x * $x + 2 * $b * $x + $c;

            if (abs($fpx) < 1.0e-12) {
                return null;
            }

            $x = $x - $fx / $fpx;
        }

        $estimationIndex = $x;

        // Future-guard: never report a completion date at or before the
        // current data point (the polynomial root may be negative or fall
        // inside the observed window).
        if ($estimationIndex <= $currentMonthIndex) {
            return null;
        }

        // Descending-branch rejection: cumulative progress is monotonic, so a
        // root where f'(x) < 0 means 100% is crossed on the curve's downward
        // tail (a<0 cubic that peaks then bends down) — that extrapolation is
        // meaningless, reject it.
        $derivativeAtRoot = 3 * $a * $estimationIndex * $estimationIndex
            + 2 * $b * $estimationIndex + $c;

        if ($derivativeAtRoot < 0) {
            return null;
        }

        if ($estimationIndex > self::MAX_REASONABLE_MONTHS) {
            return null;
        }

        // Convert index to date, preferring baseline interpolation
        if ($baselineSchedules && $baselineSchedules->isNotEmpty()) {
            $estimatedDate = $this->indexToDate($estimationIndex, $baselineSchedules);
        } else if ($plannedEndDate) {
            $extraMonths = $estimationIndex - $totalPlannedMonths;
            $estimatedDate = $plannedEndDate->copy()
                ->addMonths((int) $extraMonths)
                ->addDays((int) round(($extraMonths - (int) $extraMonths) * 30));
        } else {
            return null;
        }

        $absoluteDiff = (int) round(
            abs($estimatedDate->diffInDays($plannedEndDate))
        );

        $predictedDelayDays = $estimatedDate->isAfter($plannedEndDate)
            ? $absoluteDiff
            : -$absoluteDiff;

        $plannedEndIndex = max($totalPlannedMonths, 1);

        $completionConfidence = $this->classifyConfidence(
            $rSquared,
            $analysis['observation_count'] ?? 0,
            $estimationIndex,
            $plannedEndIndex,
            $analysis['synthetic_points_count'] ?? 0
        );

        return [
            'estimated_completion_index' => round($estimationIndex, 4),
            'estimated_completion_date'  => $estimatedDate,
            'predicted_delay_days'       => $predictedDelayDays,
            'completion_confidence'      => $completionConfidence,
        ];
    }

    // Estimate completion using standard Earned Schedule EAC_time formula.
    // Called by: CompletionComparisonService::estimateAll()
    // Uses: findEffectivePosition(), indexToDate(), classifyConfidence()
    // Formula: IEAC(t) = PD / SPI(t) = PD × AT / ES
    // Where: ES = Earned Schedule (effective position), AT = Actual Time, PD = Planned Duration
    // Returns null when baselines are empty, progress >= 100%, or R² is too low
    public function estimateEarnedSchedule(
        Carbon $plannedEndDate,
        int $currentMonthIndex,
        float $currentProgress,
        Collection $baselineSchedules,
        array $analysis
    ): ?array {

        if ($currentProgress >= 100.0) {
            return null;
        }

        $rSquared = $analysis['r_squared'] ?? 0.0;

        if ($rSquared < self::MIN_R2_FOR_ESTIMATE) {
            return null;
        }

        $schedules = $baselineSchedules->values();

        if ($schedules->isEmpty()) {
            return null;
        }

        $totalPlannedMonths = $schedules->count();

        $es = $this->findEffectivePosition($currentProgress, $schedules);

        if ($es === null) {
            return null;
        }

        $at = (float) $currentMonthIndex;

        if ($at <= 0 || $es <= 0) {
            return null;
        }

        // Schedule Performance Index (time-based)
        $spi = $es / $at;

        if ($spi <= 0) {
            return null;
        }

        // IEAC(t) = Planned Duration / SPI(t)
        $estimationIndex = $totalPlannedMonths / $spi;

        if ($estimationIndex <= $currentMonthIndex) {
            return null;
        }

        if ($estimationIndex > self::MAX_REASONABLE_MONTHS) {
            return null;
        }

        $estimatedDate = $this->indexToDate($estimationIndex, $baselineSchedules);

        $absoluteDiff = (int) round(
            abs($estimatedDate->diffInDays($plannedEndDate))
        );

        $predictedDelayDays = $estimatedDate->isAfter($plannedEndDate)
            ? $absoluteDiff
            : -$absoluteDiff;

        $plannedEndIndex = max($totalPlannedMonths, 1);

        $completionConfidence = $this->classifyConfidence(
            $rSquared,
            $analysis['observation_count'] ?? 0,
            $estimationIndex,
            $plannedEndIndex,
            $analysis['synthetic_points_count'] ?? 0
        );

        return [
            'estimated_completion_index' => round($estimationIndex, 4),
            'estimated_completion_date'  => $estimatedDate,
            'predicted_delay_days'       => $predictedDelayDays,
            'completion_confidence'      => $completionConfidence,
        ];
    }

    private function findEffectivePosition(
        float $progress,
        Collection $schedules
    ): ?float {

        if ($progress <= ($schedules[0]->planned_progress_percent ?? 0)) {
            return 1.0;
        }

        $lastIndex = $schedules->count() - 1;
        $lastProgress = $schedules[$lastIndex]->planned_progress_percent ?? 0;

        if ($progress >= $lastProgress) {
            return (float) $schedules->count();
        }

        for ($i = 0; $i < $lastIndex; $i++) {
            $lower = (float) ($schedules[$i]->planned_progress_percent ?? 0);
            $upper = (float) ($schedules[$i + 1]->planned_progress_percent ?? 0);

            if ($progress >= $lower && $progress < $upper) {
                $fraction = ($upper - $lower) > 0
                    ? ($progress - $lower) / ($upper - $lower)
                    : 0;

                return ($i + 1) + $fraction;
            }
        }

        return null;
    }

    private function indexToDate(
        float $estimationIndex,
        Collection $baselineSchedules
    ): Carbon {

        $floorIndex = (int) floor($estimationIndex);
        $fraction = $estimationIndex - $floorIndex;

        $schedules = $baselineSchedules->values();
        $count = $schedules->count();

        if ($floorIndex < $count) {
            $scheduleIndex = max(0, $floorIndex - 1);
            $baseDate = $schedules[$scheduleIndex]->planned_date;

            if ($fraction > 0.001 && $scheduleIndex + 1 < $count) {
                $nextDate = $schedules[$scheduleIndex + 1]->planned_date;
                $daysInMonth = (int) $baseDate->diffInDays($nextDate);
                $estimatedDate = $baseDate->copy()->addDays(
                    (int) round($fraction * $daysInMonth)
                );
            } else {
                $estimatedDate = $baseDate->copy();
            }
        } else {
            $lastSchedule = $schedules->last();
            if ($lastSchedule) {
                $extraMonths = $floorIndex - $count;
                $estimatedDate = $lastSchedule->planned_date->copy()->addMonths($extraMonths);
                $estimatedDate->addDays((int) round($fraction * 30));
            } else {
                $estimatedDate = Carbon::now()->addMonths((int) $estimationIndex);
            }
        }

        return $estimatedDate;
    }

    private function classifyConfidence(
        float $rSquared,
        int $observationCount,
        float $estimationIndex,
        int $plannedEndIndex,
        int $syntheticCount = 0
    ): string {

        // Use only real observations (exclude synthetic points from cross-project)
        $realObsCount = max(0, $observationCount - $syntheticCount);

        if ($estimationIndex > self::LOW_CONFIDENCE_INDEX_RATIO * $plannedEndIndex) {
            return 'Low';
        }

        if (
            $rSquared >= self::HIGH_CONFIDENCE_R2
            && $realObsCount >= self::HIGH_CONFIDENCE_OBS
            && $estimationIndex <= self::HIGH_CONFIDENCE_INDEX_RATIO * $plannedEndIndex
        ) {
            return 'High';
        }

        if (
            $rSquared >= self::MEDIUM_CONFIDENCE_R2
            && $realObsCount >= self::MEDIUM_CONFIDENCE_OBS
        ) {
            return 'Medium';
        }

        return 'Low';
    }
}

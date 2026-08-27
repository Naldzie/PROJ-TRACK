<?php

namespace App\Services\AI;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class CompletionEstimationService
{
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

        if ($rSquared < config('ai.completion.min_r2_for_estimate')) {
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

        if ($estimationIndex > config('ai.completion.max_reasonable_months')) {
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

        if ($rSquared < config('ai.completion.min_r2_for_estimate')) {
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

        if ($estimationIndex > config('ai.completion.max_reasonable_months')) {
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

    // True when the polynomial was selected AND its fit clears the R² bar —
    // i.e. the implausible branches (future-guard, descending tail, absurd
    // horizon) are what made estimatePolynomial() return null. Used by
    // CompletionComparisonService::estimateAll() to decide a class fallback is
    // warranted (A2) instead of plain "method N/A".
    // Called by: CompletionComparisonService::estimateAll()
    public function isPolynomialSelected(array $analysis): bool
    {
        return (($analysis['regression_method'] ?? 'slr') === 'polynomial')
            && (float) ($analysis['r_squared'] ?? 0.0) >= config('ai.completion.min_r2_for_estimate');
    }

    // Class-based completion sanity fallback (A2 — cross-project aggregate).
    // Called by: CompletionComparisonService::estimateAll() when the polynomial
    //            completion is implausible (past date / descending tail / absurd
    //            horizon) and a usable class reference exists.
    // Uses: interpolateClassPosition() to map current progress → class-relative
    //       elapsed month, then indexToDate() + classifyConfidence() as usual.
    // Answers: "similar projects took from currentProgress% to 100% about N
    //           months" and anchors that onto the project's own timeline.
    public function estimateFromClassReference(
        array $classReference,
        float $currentProgress,
        int $currentMonthIndex,
        int $totalPlannedMonths,
        ?Carbon $plannedEndDate = null,
        ?Collection $baselineSchedules = null,
        ?float $blendIndex = null,
        float $rSquared = 0.0,
        int $observationCount = 0,
        int $syntheticCount = 0
    ): ?array {

        if ($currentProgress >= 100.0) {
            return null;
        }

        $median = $classReference['median'] ?? [];

        if (empty($median) || $totalPlannedMonths <= 0) {
            return null;
        }

        $classPosition = $this->interpolateClassPosition($median, $currentProgress);

        if ($classPosition === null) {
            return null;
        }

        // Months remaining in CLASS time, then anchored onto project time.
        $classMonthsToGo = $totalPlannedMonths - $classPosition;

        if ($classMonthsToGo < 0) {
            return null;
        }

        $estimationIndex = (float) $currentMonthIndex + $classMonthsToGo;

        $blendPartners = ['class_aggregate'];

        // A2 §3.2 step 5: blend with the schedule-aware index when present.
        if ($blendIndex !== null && $blendIndex > $currentMonthIndex) {
            $estimationIndex = ($estimationIndex + $blendIndex) / 2;
            $blendPartners[] = 'schedule_aware';
        }

        // Same bounds as the own-model solvers: never a past date, never an
        // absurd horizon.
        if ($estimationIndex <= $currentMonthIndex) {
            return null;
        }

        if ($estimationIndex > config('ai.completion.max_reasonable_months')) {
            return null;
        }

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
            $observationCount,
            $estimationIndex,
            $plannedEndIndex,
            $syntheticCount
        );

        return [
            'estimated_completion_index' => round($estimationIndex, 4),
            'estimated_completion_date'  => $estimatedDate,
            'predicted_delay_days'       => $predictedDelayDays,
            'completion_confidence'      => $completionConfidence,
            'fallback_method'            => 'class_aggregate',
            'blend_partners'             => $blendPartners,
        ];
    }

    // Map a progress value onto the class median curve: returns the class-relative
    // month (with interpolation) where similar projects reached that progress.
    // Returns null when the class curve never reaches currentProgress within its
    // planned duration (the class has no evidence the project can finish).
    private function interpolateClassPosition(array $median, float $progress): ?float
    {
        $months = array_keys($median);

        if (empty($months)) {
            return null;
        }

        $first = $median[$months[0]] ?? 0;

        if ($progress <= $first) {
            return (float) $months[0];
        }

        $lastMonth = $months[count($months) - 1];
        $lastValue = $median[$lastMonth] ?? 0;

        if ($progress > $lastValue) {
            return null;
        }

        for ($i = 0; $i < count($months) - 1; $i++) {
            $m0 = $months[$i];
            $m1 = $months[$i + 1];
            $v0 = (float) ($median[$m0] ?? 0);
            $v1 = (float) ($median[$m1] ?? 0);

            if ($progress >= $v0 && $progress <= $v1) {
                $fraction = ($v1 - $v0) > 0
                    ? ($progress - $v0) / ($v1 - $v0)
                    : 0;

                return $m0 + $fraction;
            }
        }

        return null;
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

        if ($rSquared < config('ai.completion.min_r2_for_estimate')) {
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

        if ($estimationIndex > config('ai.completion.max_reasonable_months')) {
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
        $compConfig = config('ai.completion');

        if ($estimationIndex > $compConfig['low_confidence_index_ratio'] * $plannedEndIndex) {
            return 'Low';
        }

        if (
            $rSquared >= $compConfig['high_confidence_r2']
            && $realObsCount >= $compConfig['high_confidence_obs']
            && $estimationIndex <= $compConfig['high_confidence_index_ratio'] * $plannedEndIndex
        ) {
            return 'High';
        }

        if (
            $rSquared >= $compConfig['medium_confidence_r2']
            && $realObsCount >= $compConfig['medium_confidence_obs']
        ) {
            return 'Medium';
        }

        return 'Low';
    }
}

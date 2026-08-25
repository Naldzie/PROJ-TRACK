<?php

namespace App\Services\AI;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectExtension;
use Carbon\Carbon;

/**
 * ============================================================================
 * ContractorPerformanceService
 * ============================================================================
 *
 * Purpose
 * -------
 * Computes and stores aggregated contractor performance metrics from completed
 * project history. Answers: "How reliable is this contractor overall?"
 *
 * This is distinct from the per-project ContractorRating (regression-based
 * star rating). That answers "How did this contractor do on THIS project's
 * S-curve?" This service answers "What is their track record across all
 * completed projects?"
 *
 * Metrics Computed
 * ----------------
 * • on_time_delivery_pct  — % of completed projects finished by deadline
 * • avg_delay_days        — average days late (across late projects only)
 * • completed_projects_count — total completed projects
 * • performance_rating    — 1-5 rating derived from on-time % and avg delay
 * • last_project_completed_at — timestamp of most recent completion
 * • total_extensions_count — total approved extensions across completed projects
 * • total_extension_days   — sum of days from all approved extensions
 *
 * On-Time Calculation (Industry Standard — AACE RP 109R-19)
 * ----------------------------------------------------------
 * effective_deadline = original_planned_end_date + latest approved extension
 * is_on_time = actual_end_date <= effective_deadline
 *
 * Used By
 * -------
 * • RatingController (recompute endpoint + contractor JSON payload)
 * • ProjectProgressController::autoCompleteOnFullProgress() (auto-recompute)
 * • ProjectExtensionController::recomputeProjectDeadline() (auto-recompute)
 * • AIPredictionService (injects metrics into prediction report)
 * • RecommendationEngine (consumes metrics for corrective rules)
 *
 * Depends On
 * ----------
 * • Project model — completed projects with dates
 * • ProjectExtension model — approved extensions for deadline adjustment
 */
class ContractorPerformanceService
{
    /**
     * ------------------------------------------------------------------------
     * Recompute Performance Metrics
     * ------------------------------------------------------------------------
     *
     * Recalculates all performance metrics for a contractor from their
     * completed project history and saves them to the contractors table.
     *
     * Called When
     * -----------
     * • A project status changes to "Completed" (auto-complete at 100%)
     * • An extension is approved (deadline changed, on-time may shift)
     * • On demand via POST /admin/rating/{contractor}/recompute-performance
     * • Bulk recompute via recomputeAll()
     *
 * Workflow
 * --------
 * 1. Get all Completed projects with actual_end_date and original_planned_end_date.
 * 2. For each: compute effective_deadline (original + latest approved extension).
 * 3. Classify as on-time or late; accumulate delay days.
 * 4. Compute on_time_pct, avg_delay, performance_rating.
 * 5. Query approved extensions across completed projects for informational stats.
 * 6. Save all metrics to contractors table.
     *
     * Depends On
     * ----------
     * getEffectiveDeadline(), computeRating()
     */
    public function recompute(Contractor $contractor): array
    {
        $completed = Project::where('contractor_id', $contractor->id)
            ->where('status', 'Completed')
            ->whereNotNull('actual_end_date')
            ->whereNotNull('original_planned_end_date')
            ->get();

        if ($completed->isEmpty()) {
            $contractor->update([
                'performance_rating' => null,
                'on_time_delivery_pct' => null,
                'completed_projects_count' => 0,
                'avg_delay_days' => null,
                'last_project_completed_at' => null,
                'total_extensions_count' => 0,
                'total_extension_days' => null,
            ]);

            return $this->emptyMetrics();
        }

        $onTimeCount = 0;
        $totalDelayDays = 0;

        foreach ($completed as $project) {
            $effectiveDeadline = $this->getEffectiveDeadline($project);
            $actualEnd = Carbon::parse($project->actual_end_date);

            // On-time: actual end is on or before the effective deadline
            if ($actualEnd->lte($effectiveDeadline)) {
                $onTimeCount++;
            } else {
                // Late: accumulate positive delay days
                $totalDelayDays += (int) $effectiveDeadline->diffInDays($actualEnd, false);
            }
        }

        $total = $completed->count();
        $onTimePct = round(($onTimeCount / $total) * 100, 2);
        $avgDelayDays = round($totalDelayDays / $total, 2);
        $performanceRating = $this->computeRating($onTimePct, $avgDelayDays);
        $lastCompleted = $completed->max('actual_end_date');

        // Extension stats: count and sum of days from approved extensions
        // on completed projects (informational only, no scoring impact)
        $completedIds = $completed->pluck('id');
        $extensionsQuery = ProjectExtension::whereIn('project_id', $completedIds)
            ->where('status', 'approved');
        $totalExtensionsCount = $extensionsQuery->count();
        $totalExtensionDays = (clone $extensionsQuery)->sum('duration_days');

        $contractor->update([
            'performance_rating' => $performanceRating,
            'on_time_delivery_pct' => $onTimePct,
            'completed_projects_count' => $total,
            'avg_delay_days' => $avgDelayDays,
            'last_project_completed_at' => $lastCompleted,
            'total_extensions_count' => $totalExtensionsCount,
            'total_extension_days' => $totalExtensionDays > 0 ? $totalExtensionDays : null,
        ]);

        return [
            'performance_rating' => $performanceRating,
            'on_time_delivery_pct' => $onTimePct,
            'completed_projects_count' => $total,
            'avg_delay_days' => $avgDelayDays,
            'last_project_completed_at' => $lastCompleted,
            'total_extensions_count' => $totalExtensionsCount,
            'total_extension_days' => $totalExtensionDays > 0 ? $totalExtensionDays : null,
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Get Performance Summary
     * ------------------------------------------------------------------------
     *
     * Returns the contractor's current performance metrics without
     * recomputing. Used by AIPredictionService to inject metrics into
     * the prediction report for the RecommendationEngine.
     *
     * Called By
     * ---------
     * AIPredictionService::generatePrediction()
     */
    public function getPerformanceSummary(Contractor $contractor): array
    {
        return [
            'performance_rating' => $contractor->performance_rating,
            'on_time_delivery_pct' => $contractor->on_time_delivery_pct,
            'completed_projects_count' => $contractor->completed_projects_count,
            'avg_delay_days' => $contractor->avg_delay_days,
            'last_project_completed_at' => $contractor->last_project_completed_at,
            'total_extensions_count' => $contractor->total_extensions_count,
            'total_extension_days' => $contractor->total_extension_days,
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Compute Performance Rating (Protected)
     * ------------------------------------------------------------------------
     *
     * Derives a 1-5 integer rating from on-time percentage and average
     * delay days. Uses AND logic: both conditions must be met for each tier.
     *
     * Rating Tiers (Industry-Aligned — CPARS/USACE)
     * ----------------------------------------------
     * 5 (Superior)   — on-time >= 90% AND avg delay <= 7 days
     * 4 (Good)       — on-time >= 80% AND avg delay <= 15 days
     * 3 (Satisfactory) — on-time >= 70% AND avg delay <= 30 days
     * 2 (Below Average) — on-time >= 50% AND avg delay <= 60 days
     * 1 (Poor)       — everything else
     *
     * Depends On
     * ----------
     * getPerformanceRating() (public wrapper for test access)
     */
    protected function computeRating(float $onTimePct, float $avgDelayDays): int
    {
        return $this->getPerformanceRating($onTimePct, $avgDelayDays);
    }

    /**
     * ------------------------------------------------------------------------
     * Get Performance Rating (Public)
     * ------------------------------------------------------------------------
     *
     * Public wrapper of computeRating() for test access. Implements the
     * same 1-5 tier logic.
     *
     * Called By
     * ---------
     * computeRating(), ContractorPerformanceServiceTest
     */
    public function getPerformanceRating(float $onTimePct, float $avgDelayDays): int
    {
        if ($onTimePct >= 90 && $avgDelayDays <= 7) {
            return 5;
        }
        if ($onTimePct >= 80 && $avgDelayDays <= 15) {
            return 4;
        }
        if ($onTimePct >= 70 && $avgDelayDays <= 30) {
            return 3;
        }
        if ($onTimePct >= 50 && $avgDelayDays <= 60) {
            return 2;
        }
        return 1;
    }

    /**
     * ------------------------------------------------------------------------
     * Recompute All Contractors
     * ------------------------------------------------------------------------
     *
     * Bulk recompute performance metrics for every contractor. Used for
     * data repair or after migration.
     *
     * Called By
     * ---------
     * Manual admin trigger (potential future CLI command)
     */
    public function recomputeAll(): void
    {
        Contractor::with('projects')->each(fn(Contractor $c) => $this->recompute($c));
    }

    /**
     * ------------------------------------------------------------------------
     * Get Effective Deadline
     * ------------------------------------------------------------------------
     *
     * Determines the effective deadline for a completed project using the
     * extension-adjusted date (industry standard — AACE RP 109R-19, FIDIC
     * Sub-Clause 8.4).
     *
     * Logic
     * -----
     * • If approved extensions exist: use the latest approved extension's
     *   approved_new_end_date (or new_end_date as fallback).
     * • If no approved extensions: use original_planned_end_date.
     *
     * Why Not planned_end_date?
     * -------------------------
     * planned_end_date is mutable and updated by recomputeProjectDeadline().
     * Using original + extensions explicitly ensures the calculation matches
     * the industry-standard formula and is testable in isolation.
     *
     * Depends On
     * ----------
     * Project->extensions() relationship, ProjectExtension->status
     */
    protected function getEffectiveDeadline(Project $project): Carbon
    {
        $latestApproved = $project->extensions()
            ->where('status', 'approved')
            ->reorder('extension_number', 'desc')
            ->first();

        if ($latestApproved) {
            return Carbon::parse(
                $latestApproved->approved_new_end_date ?? $latestApproved->new_end_date
            );
        }

        return Carbon::parse($project->original_planned_end_date);
    }

    /**
     * ------------------------------------------------------------------------
     * Empty Metrics Placeholder
     * ------------------------------------------------------------------------
     *
     * Returns a null/zero metrics array for contractors with no completed
     * projects. Used as a return value from recompute() when no data exists.
     */
    protected function emptyMetrics(): array
    {
        return [
            'performance_rating' => null,
            'on_time_delivery_pct' => null,
            'completed_projects_count' => 0,
            'avg_delay_days' => null,
            'last_project_completed_at' => null,
            'total_extensions_count' => 0,
            'total_extension_days' => null,
        ];
    }
}

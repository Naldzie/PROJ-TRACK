<?php

namespace App\Services\AI;

use App\Models\Contractor;
use App\Models\Project;
use Carbon\Carbon;

class ContractorPerformanceService
{
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
            ]);

            return $this->emptyMetrics();
        }

        $onTimeCount = 0;
        $totalDelayDays = 0;

        foreach ($completed as $project) {
            $effectiveDeadline = $this->getEffectiveDeadline($project);
            $actualEnd = Carbon::parse($project->actual_end_date);

            if ($actualEnd->lte($effectiveDeadline)) {
                $onTimeCount++;
            } else {
                $totalDelayDays += (int) $effectiveDeadline->diffInDays($actualEnd, false);
            }
        }

        $total = $completed->count();
        $onTimePct = round(($onTimeCount / $total) * 100, 2);
        $avgDelayDays = round($totalDelayDays / $total, 2);
        $performanceRating = $this->computeRating($onTimePct, $avgDelayDays);
        $lastCompleted = $completed->max('actual_end_date');

        $contractor->update([
            'performance_rating' => $performanceRating,
            'on_time_delivery_pct' => $onTimePct,
            'completed_projects_count' => $total,
            'avg_delay_days' => $avgDelayDays,
            'last_project_completed_at' => $lastCompleted,
        ]);

        return [
            'performance_rating' => $performanceRating,
            'on_time_delivery_pct' => $onTimePct,
            'completed_projects_count' => $total,
            'avg_delay_days' => $avgDelayDays,
            'last_project_completed_at' => $lastCompleted,
        ];
    }

    public function getPerformanceSummary(Contractor $contractor): array
    {
        return [
            'performance_rating' => $contractor->performance_rating,
            'on_time_delivery_pct' => $contractor->on_time_delivery_pct,
            'completed_projects_count' => $contractor->completed_projects_count,
            'avg_delay_days' => $contractor->avg_delay_days,
            'last_project_completed_at' => $contractor->last_project_completed_at,
        ];
    }

    protected function computeRating(float $onTimePct, float $avgDelayDays): int
    {
        return $this->getPerformanceRating($onTimePct, $avgDelayDays);
    }

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

    public function recomputeAll(): void
    {
        Contractor::with('projects')->each(fn(Contractor $c) => $this->recompute($c));
    }

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

    protected function emptyMetrics(): array
    {
        return [
            'performance_rating' => null,
            'on_time_delivery_pct' => null,
            'completed_projects_count' => 0,
            'avg_delay_days' => null,
            'last_project_completed_at' => null,
        ];
    }
}

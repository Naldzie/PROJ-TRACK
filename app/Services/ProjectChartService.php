<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectActivity;
use App\Models\ProjectProgressHistory;
use App\Models\ProjectExtension;
use Illuminate\Support\Collection;

class ProjectChartService
{
    private ProjectOverviewService $overviewService;

    public function __construct(
        ProjectOverviewService $overviewService
    ) {
        $this->overviewService = $overviewService;
    }

    // Prepare variance + burndown trend data for the Progress tab (AJAX)
    public function prepareTrendData(Project $project): array
    {
        $baselineSchedules = ProjectBaselineSchedule::where('project_id', $project->id)
            ->orderBy('planned_date', 'asc')
            ->get();

        $progressHistory = ProjectProgressHistory::where('project_id', $project->id)
            ->orderByDesc('month_label')
            ->orderByDesc('inspection_date')
            ->orderByDesc('created_at')
            ->get();

        $activities = ProjectActivity::where('project_id', $project->id)
            ->orderBy('month_label')
            ->orderBy('activity_name')
            ->get();

        return [
            'ready'    => true,
            'variance' => $this->buildVarianceTrendData($baselineSchedules, $progressHistory),
            'burndown' => $this->buildActivityBurndownData($baselineSchedules, $activities),
        ];
    }

    // Build the S-Curve chart data (planned vs actual vs extension)
    public function prepareChartData(Project $project, $baselineSchedules = null, $progressHistory = null, $activities = null): array
    {
        $baselineSchedules = $baselineSchedules
            ?? ProjectBaselineSchedule::where('project_id', $project->id)
                ->orderBy('planned_date', 'asc')
                ->get();

        if ($baselineSchedules->isNotEmpty()) {
            $chartData = $this->buildBaselineScheduleChartData($project, $baselineSchedules, $progressHistory, $activities);

            if (!$chartData['has_data']) {
                return ['ready' => false];
            }

            return [
                'ready' => true,
                'model' => 'baseline_schedule',
                'labels' => $chartData['labels'],
                'tick_timestamps' => $chartData['tick_timestamps'],
                'frozen_planned_data' => $chartData['frozen_planned_data'],
                'adjusted_planned_data' => $chartData['planned_data'],
                'actual_data' => $chartData['actual_data'],
                'monthly_summary' => $chartData['monthly_summary'],
                'has_extension' => $chartData['has_extension'],
                'y_max' => $chartData['y_max'],
            ];
        }

        return ['ready' => false];
    }

    // Build the monthly planned vs actual chart data from baseline schedules
    public function buildBaselineScheduleChartData(Project $project, $baselineSchedules = null, $progressHistory = null, $activities = null): array
    {
        try {
            $baselineSchedules = $baselineSchedules
                ?? ProjectBaselineSchedule::where('project_id', $project->id)
                    ->orderBy('planned_date', 'asc')
                    ->get();

            $allProgressRecords = $progressHistory
                ?? ProjectProgressHistory::where('project_id', $project->id)
                    ->orderBy('month_label', 'asc')
                    ->orderBy('inspection_date', 'asc')
                    ->orderBy('created_at', 'asc')
                    ->get();

            $activities = $activities
                ?? ProjectActivity::where('project_id', $project->id)
                    ->orderBy('month_label')
                    ->get();

            if ($baselineSchedules->isEmpty()) {
                return [
                    'labels' => [],
                    'planned_data' => [],
                    'actual_data' => [],
                    'tick_timestamps' => [],
                    'monthly_summary' => [],
                    'has_data' => false,
                ];
            }

            $labels = [];
            $tickTimestamps = [];
            $frozenPlannedData = [];
            $plannedData = [];
            $actualDataPoints = [];
            $monthlySummary = [];

            foreach ($baselineSchedules as $schedule) {
                $monthLabel = $schedule->month_label;
                $labels[] = $monthLabel;

                $plannedTs = $schedule->planned_date instanceof \Carbon\Carbon
                    ? $schedule->planned_date->timestamp * 1000
                    : (strtotime((string) $schedule->planned_date) * 1000);
                $tickTimestamps[] = $plannedTs;

                $frozenPlannedData[] = [
                    'x' => $plannedTs,
                    'y' => $schedule->original_planned_progress_percent !== null
                        ? (float) $schedule->original_planned_progress_percent
                        : null,
                ];

                $plannedData[] = [
                    'x' => $plannedTs,
                    'y' => $schedule->planned_progress_percent,
                ];

                // Monthly summary still uses the latest record per month (unchanged)
                $latestRecord = $allProgressRecords
                    ->where('month_label', $monthLabel)
                    ->sortByDesc(fn ($r) => $r->inspection_date?->timestamp ?? 0)
                    ->sortByDesc(fn ($r) => $r->created_at?->timestamp ?? 0)
                    ->first();
                $actualPercentDisplay = $latestRecord ? $latestRecord->actual_progress_percent : 0;
                $actualPercent = $latestRecord ? $latestRecord->actual_progress_percent : null;

                $variance = $actualPercentDisplay - $schedule->planned_progress_percent;

                $status = 'On Track';
                if ($variance > 5) {
                    $status = 'Ahead';
                } elseif ($variance < -5) {
                    $status = 'Behind';
                }

                $monthActivities = $activities->where('month_label', $monthLabel);
                $activitiesCompleted = $monthActivities->where('is_completed', true)->count();
                $activitiesTotal = $monthActivities->count();

                $monthlySummary[] = [
                    'month_label' => $monthLabel,
                    'month_display' => (new \DateTime($monthLabel))->format('M Y'),
                    'planned_percent' => $schedule->planned_progress_percent,
                    'actual_percent' => $actualPercentDisplay,
                    'variance' => round($variance, 2),
                    'status' => $status,
                    'activities_completed' => $activitiesCompleted,
                    'activities_total' => $activitiesTotal,
                    'description' => $schedule->description,
                ];
            }

            // Build actual_data_points from ALL individual progress records
            foreach ($allProgressRecords as $record) {
                $recordDate = $record->inspection_date ?? $record->created_at;
                if ($recordDate && $record->actual_progress_percent !== null) {
                    $recordTs = $recordDate instanceof \Carbon\Carbon
                        ? $recordDate->timestamp * 1000
                        : (strtotime((string) $recordDate) * 1000);
                    $actualDataPoints[] = [
                        'x' => $recordTs,
                        'y' => (float) $record->actual_progress_percent,
                        'month_label' => $record->month_label,
                    ];
                }
            }

            $hasExtension = $project->extension_count > 0;

            // y_max includes actual data values and planned values
            $allPlannedY = array_merge(
                array_column(array_filter($plannedData, fn($v) => $v['y'] !== null), 'y'),
                array_column(array_filter($frozenPlannedData, fn($v) => $v['y'] !== null), 'y')
            );
            $allActualY = array_column($actualDataPoints, 'y');
            $allValues = array_merge($allPlannedY, $allActualY);
            $yMax = !empty($allValues) ? max(max($allValues) + 10, 100) : 100;

            return [
                'labels' => $labels,
                'tick_timestamps' => $tickTimestamps,
                'frozen_planned_data' => $frozenPlannedData,
                'planned_data' => $plannedData,
                'actual_data' => $actualDataPoints,
                'monthly_summary' => $monthlySummary,
                'has_data' => !empty($plannedData),
                'has_extension' => $hasExtension,
                'y_max' => $yMax,
            ];
        } catch (\Exception $e) {
            \Log::error('Error building baseline schedule chart data: ' . $e->getMessage());
            return [
                'labels' => [],
                'planned_data' => [],
                'actual_data' => [],
                'tick_timestamps' => [],
                'frozen_planned_data' => [],
                'monthly_summary' => [],
                'has_data' => false,
                'has_extension' => false,
                'y_max' => 100,
            ];
        }
    }

    // Build the variance trend data for trend chart
    private function buildVarianceTrendData(Collection $baselineSchedules, Collection $progressHistory): array
    {
        if ($baselineSchedules->isEmpty()) {
            return [
                'labels' => [],
                'data' => [],
                'is_placeholder' => false,
            ];
        }

        $latestByMonth = $progressHistory
            ->filter(fn ($record) => !empty($record->month_label))
            ->sortByDesc(fn ($record) => $record->created_at?->timestamp ?? 0)
            ->unique('month_label')
            ->keyBy('month_label');

        $labels = [];
        $varianceData = [];

        foreach ($baselineSchedules as $schedule) {
            $monthLabel = $schedule->month_label;

            if (!$monthLabel || !$latestByMonth->has($monthLabel)) {
                continue;
            }

            $record = $latestByMonth->get($monthLabel);
            $actual = (float) ($record->actual_progress_percent ?? $record->progress_percentage ?? 0);
            $planned = (float) ($record->planned_progress_percent ?? $schedule->planned_progress_percent ?? 0);
            $labels[] = $this->overviewService->formatMonthLabelForDisplay($monthLabel);

            $varianceData[] = round($actual - $planned, 2);
        }

        return [
            'labels' => $labels,
            'data' => $varianceData,
            'is_placeholder' => false,
        ];
    }

    // Build activity burndown chart data
    private function buildActivityBurndownData(Collection $baselineSchedules, Collection $activities): array
    {
        $labels = [];
        $planned = [];
        $completed = [];

        if ($baselineSchedules->isNotEmpty()) {
            foreach ($baselineSchedules as $schedule) {
                if (!$schedule->month_label) {
                    continue;
                }

                $monthActivities = $activities->where('month_label', $schedule->month_label);

                $labels[] = $this->overviewService->formatMonthLabelForDisplay($schedule->month_label);
                $planned[] = $monthActivities->count();
                $completed[] = $monthActivities->where('is_completed', true)->count();
            }
        } elseif ($activities->isNotEmpty()) {
            $grouped = $activities
                ->filter(fn ($activity) => !empty($activity->month_label))
                ->groupBy('month_label')
                ->sortKeys();

            foreach ($grouped as $monthLabel => $monthActivities) {
                $labels[] = $this->overviewService->formatMonthLabelForDisplay((string) $monthLabel);
                $planned[] = $monthActivities->count();
                $completed[] = $monthActivities->where('is_completed', true)->count();
            }
        }

        return [
            'labels' => $labels,
            'planned' => $planned,
            'completed' => $completed,
            'is_placeholder' => false,
        ];
    }

    // Detect missing months, overdue months, and decreasing anomalies
    private function buildQualityChecks(Collection $baselineSchedules, Collection $progressHistory): array
    {
        $recordedMonths = $progressHistory->pluck('month_label')->filter()->unique()->values();
        $expectedMonths = $baselineSchedules->pluck('month_label')->filter()->unique()->values();

        $missingMonths = $expectedMonths
            ->diff($recordedMonths)
            ->map(fn ($monthLabel) => $this->overviewService->formatMonthLabelForDisplay($monthLabel))
            ->values()
            ->all();

        $overdueMissingMonths = $baselineSchedules
            ->filter(function ($schedule) use ($recordedMonths) {
                return $schedule->planned_date
                    && $schedule->planned_date->lt(now()->startOfDay())
                    && !$recordedMonths->contains($schedule->month_label);
            })
            ->map(fn ($schedule) => $this->overviewService->formatMonthLabelForDisplay($schedule->month_label))
            ->values()
            ->all();

        $chronological = $progressHistory
            ->sortBy(function ($record) {
                $monthTs = $record->inspection_date?->timestamp
                    ?? 0;

                return ($monthTs * 100000) + ($record->created_at?->timestamp ?? 0);
            })
            ->values();

        $decreasingAnomalies = [];
        $previous = null;

        foreach ($chronological as $record) {
            $current = (float) ($record->actual_progress_percent ?? $record->progress_percentage ?? 0);

            if ($previous !== null && $current < $previous) {
                $decreasingAnomalies[] = [
                    'month' => $this->overviewService->formatMonthLabelForDisplay($record->month_label),
                    'previous' => round($previous, 1),
                    'current' => round($current, 1),
                    'source' => 'live',
                ];
            }

            $previous = $current;
        }

        return [
            'missing_months' => $missingMonths,
            'overdue_missing_months' => $overdueMissingMonths,
            'decreasing_anomalies' => $decreasingAnomalies,
            'has_flags' => !empty($missingMonths) || !empty($overdueMissingMonths) || !empty($decreasingAnomalies),
        ];
    }

    // Return monthly planned vs actual summary
    public function getSCurveMonthlySummary($projectId)
    {
        $project = Project::find($projectId);
        if (!$project) {
            return [];
        }

        $baselineSchedules = ProjectBaselineSchedule::where('project_id', $projectId)
            ->orderBy('planned_date')
            ->get();

        $progressHistory = ProjectProgressHistory::where('project_id', $projectId)
            ->orderBy('month_label')
            ->get();

        $extensions = ProjectExtension::where('project_id', $projectId)
            ->where('approved_at', '!=', null)
            ->orderBy('approved_at')
            ->latest()
            ->first();

        $summary = [];
        foreach ($baselineSchedules as $schedule) {
            $monthLabel = $schedule->month_label;

            $actual = $progressHistory->firstWhere('month_label', $monthLabel);

            $actualPercent = $actual ? $actual->actual_progress_percent : null;
            $variance = $actualPercent !== null ? $actualPercent - $schedule->planned_progress_percent : null;

            $status = 'No Data';
            if ($actualPercent !== null) {
                if ($variance < -5) {
                    $status = 'Behind';
                } elseif ($variance > 5) {
                    $status = 'Ahead';
                } else {
                    $status = 'On Track';
                }
            }

            $summary[] = [
                'month_label' => $monthLabel,
                'planned_date' => $schedule->planned_date,
                'planned_percent' => $schedule->planned_progress_percent,
                'actual_percent' => $actualPercent,
                'variance_percent' => $variance,
                'status' => $status,
                'remarks' => $actual ? $actual->remarks : null,
            ];
        }

        return $summary;
    }

    // Get the latest recorded progress % for a project
    public function getCurrentProgress($projectId)
    {
        $latest = ProjectProgressHistory::where('project_id', $projectId)
            ->orderBy('month_label', 'desc')
            ->first();

        return $latest ? $latest->actual_progress_percent : 0;
    }

    // Get monthly summary from chart data builder
    public function getMonthlySummary(Project $project): array
    {
        $chartData = $this->buildBaselineScheduleChartData($project);
        return $chartData['monthly_summary'] ?? [];
    }
}

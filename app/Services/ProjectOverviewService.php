<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectActivity;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProjectOverviewService
{
    // Build and return the header overview stats for the project detail page
    public function getOverviewStats(Project $project, $progressHistory = null): array
    {
        $activities = $project->activities;
        $totalActivities = $activities->count();
        $completedActivities = $activities->where('is_completed', true)->count();
        $pendingActivities = max(0, $totalActivities - $completedActivities);
        $activityCompletionRate = $totalActivities > 0
            ? round(($completedActivities / $totalActivities) * 100, 1)
            : 0;
        $latestProgress = $progressHistory
            ? $progressHistory->first()
            : $project->progressHistory()
                ->orderByDesc('month_label')
                ->orderByDesc('inspection_date')
                ->orderByDesc('created_at')
                ->first();
        $latestProgressValue = null;
        $latestProgressDate = null;

        if ($latestProgress) {
            $latestProgressValue = $latestProgress->actual_progress_percent ?? $latestProgress->progress_percentage ?? null;
            $latestProgressDate = $latestProgress->inspection_date
                ?? $latestProgress->created_at;
        }
        $isCompleted = $project->status === 'Completed';
        
        if ($isCompleted) {
            $timeVariance = $project->getCompletionDelay();
            $timeVarianceLabel = '';
            $timeVarianceColor = 'blue';

            if ($timeVariance !== null) {
                if ($timeVariance === 0) {
                    $timeVarianceLabel = 'On Schedule';
                    $timeVarianceColor = 'green';
                } elseif ($timeVariance > 0) {
                    $timeVarianceLabel = $timeVariance . ' days late';
                    $timeVarianceColor = 'orange';
                } else {
                    $timeVarianceLabel = abs($timeVariance) . ' days early';
                    $timeVarianceColor = 'green';
                }
            } else {
                $timeVarianceLabel = 'N/A';
            }
            
            return [
                'progress' => $project->getProgressPercentage(),
                'totalActivities' => $totalActivities,
                'completedActivities' => $completedActivities,
                'pendingActivities' => $pendingActivities,
                'activityCompletionRate' => $activityCompletionRate,
                'latestProgressValue' => $latestProgressValue,
                'latestProgressDate' => $latestProgressDate,
                'totalBudget' => $project->approved_budget ?? 0,
                'isCompleted' => true,
                'timeVariance' => $timeVariance,
                'timeVarianceLabel' => $timeVarianceLabel,
                'timeVarianceColor' => $timeVarianceColor,
            ];
        } else {
            $daysRemaining = $this->calculateDaysRemaining($project);
            $endDateFormatted = $project->planned_end_date 
                ? $project->planned_end_date->format('M d, Y')
                : 'Not set';
            
            return [
                'progress' => $project->getProgressPercentage(),
                'totalActivities' => $totalActivities,
                'completedActivities' => $completedActivities,
                'pendingActivities' => $pendingActivities,
                'activityCompletionRate' => $activityCompletionRate,
                'latestProgressValue' => $latestProgressValue,
                'latestProgressDate' => $latestProgressDate,
                'totalBudget' => $project->approved_budget ?? 0,
                'isCompleted' => false,
                'daysRemaining' => $daysRemaining,
                'endDateFormatted' => $endDateFormatted,
            ];
        }
    }

    // Return the progress metrics (percent, status label, color)
    public function getProgressMetrics(Project $project): array
    {
        return [
            'percentComplete' => $project->getProgressPercentage(),
            'statusLabel' => $project->status,
            'statusColor' => $this->getStatusColor($project->status),
        ];
    }

    // Fetch all activities for a project, ordered by month
    public function getActivities($projectId)
    {
        return ProjectActivity::where('project_id', $projectId)
            ->orderBy('month_label')
            ->get();
    }

    // Fetch activities for a specific month
    public function getActivitiesByMonth($projectId, $monthLabel)
    {
        return ProjectActivity::where('project_id', $projectId)
            ->where('month_label', $monthLabel)
            ->orderBy('activity_name')
            ->get();
    }

    // Calculate total activities and completion rate
    public function getActivitiesStatistics($projectId)
    {
        $activities = ProjectActivity::where('project_id', $projectId)->get();
        
        $total = $activities->count();
        $completed = $activities->where('is_completed', true)->count();
        $pending = $total - $completed;
        $completionRate = $total > 0 ? ($completed / $total) * 100 : 0;

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'completion_rate' => round($completionRate, 2),
        ];
    }

    // Format a Y-m or Y-m-d label into "Mon YYYY" display format
    public function formatMonthLabelForDisplay(?string $monthLabel): string
    {
        if (empty($monthLabel)) {
            return 'N/A';
        }

        foreach (['Y-m', 'Y-m-d'] as $format) {
            try {
                return Carbon::createFromFormat($format, $monthLabel)->format('M Y');
            } catch (\Throwable $e) {
            }
        }

        try {
            return Carbon::parse($monthLabel)->format('M Y');
        } catch (\Throwable $e) {
            return $monthLabel;
        }
    }

    // Calculate remaining days until project end date
    public function calculateDaysRemaining(Project $project): int
    {
        if (!$project->planned_end_date) return 0;
        return (int) max(0, floor(now()->diffInDays($project->planned_end_date, false)));
    }

    // Map project status to a display color
    public function getStatusColor(string $status): string
    {
        return match ($status) {
            'Completed' => 'green',
            'Ongoing' => 'blue',
            'Planned' => 'gray',
            'Suspended' => 'orange',
            'Cancelled' => 'red',
            default => 'gray',
        };
    }
}

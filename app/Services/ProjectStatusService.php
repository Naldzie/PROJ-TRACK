<?php

namespace App\Services;

use App\Models\Project;

class ProjectStatusService
{
    // Single source of truth for risk tiering, used by both getProjectStatus and
    // any future dashboard callers, so they can never disagree about risk level.
    public function getRiskLevel(float $behindPercent): array
    {
        $gap = config('ai.risk.gap');

        if ($behindPercent <= $gap['ahead']) {
            return ['level' => 'ahead', 'label' => 'Ahead of Schedule', 'color' => 'green'];
        }
        if ($behindPercent <= $gap['on_track']) {
            return ['level' => 'on_track', 'label' => 'On Track', 'color' => 'green'];
        }
        if ($behindPercent < $gap['low']) {
            return ['level' => 'low_risk', 'label' => 'Low Risk', 'color' => 'yellow'];
        }
        if ($behindPercent < $gap['medium']) {
            return ['level' => 'medium_risk', 'label' => 'Medium Risk', 'color' => 'orange'];
        }
        return ['level' => 'high_risk', 'label' => 'High Risk', 'color' => 'red'];
    }

    // Determine project on_track/behind/at_risk status from latest progress record
    public function getProjectStatus(Project $project, $progressHistory = null): array
    {
        try {
            $latestProgress = $progressHistory
                ? $progressHistory->first()
                : \App\Models\ProjectProgressHistory::where('project_id', $project->id)
                    ->orderBy('month_label', 'desc')
                    ->first();

            if (!$latestProgress) {
                return [
                    'status' => 'no_data',
                    'label' => 'No Progress Records',
                    'color' => 'gray',
                    'variance' => 0,
                    'planned_percent' => 0,
                    'actual_percent' => 0,
                    'message' => 'Start recording monthly progress to track status',
                    'last_inspection_date' => null,
                ];
            }

            $variance = $latestProgress->variance_percent ?? 0;
            $actualPercent = $latestProgress->actual_progress_percent ?? 0;
            $plannedPercent = $latestProgress->planned_progress_percent ?? 0;

            $risk = $this->getRiskLevel(-$variance);

            $statusMap = [
                'ahead'       => ['status' => 'ahead',    'message' => 'Project is performing better than planned'],
                'on_track'    => ['status' => 'on_track', 'message' => 'Project progress matches planned targets'],
                'low_risk'    => ['status' => 'behind',   'message' => 'Project is behind planned targets'],
                'medium_risk' => ['status' => 'behind',   'message' => 'Project is behind planned targets'],
                'high_risk'   => ['status' => 'at_risk',  'message' => 'Project is significantly behind schedule'],
            ];

            $mapped = $statusMap[$risk['level']];

            return [
                'status' => $mapped['status'],
                'label' => $risk['label'],
                'color' => $risk['color'],
                'variance' => round($variance, 2),
                'planned_percent' => $plannedPercent,
                'actual_percent' => $actualPercent,
                'message' => $mapped['message'],
                'last_inspection_date' => $latestProgress->inspection_date?->format('M d, Y'),
            ];
        } catch (\Exception $e) {
            \Log::error('Error getting project status: ' . $e->getMessage());
            return [
                'status' => 'error',
                'label' => 'Error',
                'color' => 'gray',
                'variance' => 0,
                'planned_percent' => 0,
                'actual_percent' => 0,
                'message' => 'Unable to determine project status',
                'last_inspection_date' => null,
            ];
        }
    }
}

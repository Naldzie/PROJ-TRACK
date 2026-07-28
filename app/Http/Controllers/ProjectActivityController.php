<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectBaselineSchedule;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ProjectActivityController
 * 
 * Handles all ProjectActivity (monthly activity checklists) operations:
 * - Retrieving activities for a specific month
 * - Creating new activities
 * - Deleting activities
 * - Toggling activity completion status
 * - Dashboard activity display
 * 
 * Moved from ProjectController as part of Phase 2 refactor (April 2026)
 */
class ProjectActivityController extends Controller
{
    public function getActivitiesByMonth(Request $request, Project $project)
    {
        try {
            $monthLabel = $request->query('month_label');
            
            if (!$monthLabel) {
                return response()->json([
                    'success' => false,
                    'message' => 'month_label query parameter is required'
                ], 422);
            }

            // Get activities for this month
            $activities = ProjectActivity::where('project_id', $project->id)
                ->where('month_label', $monthLabel)
                ->orderBy('activity_name', 'asc')
                ->get()
                ->map(function($activity) {
                    return [
                        'id' => $activity->id,
                        'activity_name' => $activity->activity_name,
                        'description' => $activity->description,
                        'is_completed' => (bool) $activity->is_completed,
                        'month_label' => $activity->month_label
                    ];
                });

            // Get S-Curve planned target for this month
            $baselineSchedule = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('month_label', $monthLabel)
                ->first();

            $plannedProgressPercent = $baselineSchedule ? $baselineSchedule->planned_progress_percent : 0;
            $monthDate = $baselineSchedule ? $baselineSchedule->planned_date->format('Y-m-d') : date('Y-m-d');

            return response()->json([
                'success' => true,
                'activities' => $activities,
                'planned_progress_percent' => $plannedProgressPercent,
                'month_date' => $monthDate,
                'count' => $activities->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching activities: ' . $e->getMessage()
            ], 500);
        }
    }

    public function storeActivity(Request $request, Project $project)
    {
        try {
            $validated = $request->validate([
                'activity_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'month_label' => 'required|string|regex:/^\d{4}-\d{2}(-\d{2})?/'
            ]);

            // Get Baseline Schedule for this month to get the planned date
            $baselineSchedule = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('month_label', $validated['month_label'])
                ->first();

            if (!$baselineSchedule) {
                return response()->json([
                    'success' => false,
                    'message' => 'No baseline schedule exists for this month. Create one first.'
                ], 422);
            }

            // Create activity
            $activity = ProjectActivity::create([
                'project_id' => $project->id,
                'baseline_schedule_id' => $baselineSchedule->id,
                'activity_name' => $validated['activity_name'],
                'description' => $validated['description'],
                'month_label' => $validated['month_label'],
                'is_completed' => false
            ]);

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'Add Checklist Item',
                'description' => auth()->user()->name . " added activity \"{$activity->activity_name}\" for project: {$project->title} ({$validated['month_label']})",
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Activity created successfully',
                'activity' => $activity
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating activity: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteActivity(Project $project, $activityId)
    {
        try {
            $activity = ProjectActivity::where('project_id', $project->id)
                ->where('id', $activityId)
                ->firstOrFail();

            if ($activity->is_completed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete a completed activity.'
                ], 422);
            }

            $activityName = $activity->activity_name;

            $activity->delete();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'Delete Checklist Item',
                'description' => auth()->user()->name . " deleted activity \"{$activityName}\" from project: {$project->title}",
                'ip_address' => request()->ip(),
                'visibility' => 'admin_only',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Activity deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting activity: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getProjectActivities(Request $request)
    {
        try {
            $projectId = $request->query('project_id');
            $monthLabel = $request->query('month_label');

            if (!$projectId || !$monthLabel) {
                return response()->json([
                    'success' => false,
                    'message' => 'project_id and month_label parameters are required'
                ], 422);
            }

            // Verify project exists and user has access
            $project = Project::findOrFail($projectId);

            // Get activities for this month
            $activities = ProjectActivity::where('project_id', $projectId)
                ->where('month_label', $monthLabel)
                ->orderBy('activity_name', 'asc')
                ->get()
                ->map(function($activity) {
                    return [
                        'id' => $activity->id,
                        'activity_name' => $activity->activity_name,
                        'description' => $activity->description,
                        'is_completed' => (bool) $activity->is_completed,
                        'month_label' => $activity->month_label
                    ];
                });

            return response()->json([
                'success' => true,
                'activities' => $activities,
                'count' => $activities->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching activities: ' . $e->getMessage()
            ], 500);
        }
    }

    public function toggleActivityCompletion(Request $request, $activityId)
    {
        try {
            $validated = $request->validate([
                'is_completed' => 'required|boolean'
            ]);

            $activity = ProjectActivity::findOrFail($activityId);
            $activity->update([
                'is_completed' => $validated['is_completed']
            ]);

            $statusWord = $validated['is_completed'] ? 'completed' : 'reopened';
            $projectTitle = $activity->project->title ?? 'Unknown Project';

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'Update Checklist Item',
                'description' => auth()->user()->name . " marked \"{$activity->activity_name}\" as {$statusWord} for project: {$projectTitle}",
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Activity completion status updated',
                'activity' => [
                    'id' => $activity->id,
                    'activity_name' => $activity->activity_name,
                    'is_completed' => (bool) $activity->is_completed,
                    'month_label' => $activity->month_label
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating activity: ' . $e->getMessage()
            ], 500);
        }
    }
}
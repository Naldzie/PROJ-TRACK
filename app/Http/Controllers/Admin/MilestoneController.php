<?php
// New files(for merging)
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MilestoneController extends Controller
{
    /**
     * Get all milestones for a project
     */
    public function index($projectId)
    {
        $project = Project::with('milestones')->findOrFail($projectId);
        
        return response()->json([
            'success' => true,
            'milestones' => $project->milestones,
            'project_progress' => $project->calculateProgressFromMilestones(),
            'total_weight' => $project->milestones->sum('weight_percentage'),
        ]);
    }

    /**
     * Store a new milestone
     */
    public function store(Request $request, $projectId)
    {
        $project = Project::findOrFail($projectId);
        
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'weight_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'planned_start_date' => ['nullable', 'date'],
            'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'actual_start_date' => ['nullable', 'date'],
            'actual_end_date' => ['nullable', 'date', 'after_or_equal:actual_start_date'],
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:Not Started,In Progress,Completed,Delayed,On Hold'],
            'remarks' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['project_id'] = $project->id;
        $validated['progress_percentage'] = $validated['progress_percentage'] ?? 0;
        $validated['status'] = $validated['status'] ?? 'Not Started';

        $milestone = ProjectMilestone::create($validated);
        
        // Auto-update status based on progress
        $milestone->updateStatusFromProgress()->save();

        return response()->json([
            'success' => true,
            'message' => 'Milestone added successfully.',
            'milestone' => $milestone->fresh(),
            'project_progress' => $project->getProgressPercentage(),
        ]);
    }

    /**
     * Update a milestone
     */
    public function update(Request $request, $projectId, $milestoneId)
    {
        $project = Project::findOrFail($projectId);
        $milestone = ProjectMilestone::where('project_id', $projectId)
            ->findOrFail($milestoneId);
        
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'weight_percentage' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'planned_start_date' => ['nullable', 'date'],
            'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'actual_start_date' => ['nullable', 'date'],
            'actual_end_date' => ['nullable', 'date', 'after_or_equal:actual_start_date'],
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:Not Started,In Progress,Completed,Delayed,On Hold'],
            'remarks' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'string', 'max:255'],
        ]);

        $milestone->fill($validated);
        $milestone->updateStatusFromProgress();
        $milestone->save();

        return response()->json([
            'success' => true,
            'message' => 'Milestone updated successfully.',
            'milestone' => $milestone->fresh(),
            'project_progress' => $project->getProgressPercentage(),
        ]);
    }

    /**
     * Delete a milestone
     */
    public function destroy($projectId, $milestoneId)
    {
        $project = Project::findOrFail($projectId);
        $milestone = ProjectMilestone::where('project_id', $projectId)
            ->findOrFail($milestoneId);
        
        $milestone->delete();

        return response()->json([
            'success' => true,
            'message' => 'Milestone deleted successfully.',
            'project_progress' => $project->getProgressPercentage(),
        ]);
    }

    /**
     * Bulk store milestones
     */
    public function bulkStore(Request $request, $projectId)
    {
        $project = Project::findOrFail($projectId);
        
        $validated = $request->validate([
            'milestones' => ['required', 'array', 'min:1'],
            'milestones.*.name' => ['required', 'string', 'max:255'],
            'milestones.*.description' => ['nullable', 'string'],
            'milestones.*.weight_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'milestones.*.planned_start_date' => ['nullable', 'date'],
            'milestones.*.planned_end_date' => ['nullable', 'date'],
            'milestones.*.actual_start_date' => ['nullable', 'date'],
            'milestones.*.actual_end_date' => ['nullable', 'date'],
            'milestones.*.progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'milestones.*.status' => ['nullable', 'in:Not Started,In Progress,Completed,Delayed,On Hold'],
        ]);

        $createdMilestones = [];

        DB::transaction(function () use ($project, $validated, &$createdMilestones) {
            foreach ($validated['milestones'] as $milestoneData) {
                $milestoneData['project_id'] = $project->id;
                $milestoneData['progress_percentage'] = $milestoneData['progress_percentage'] ?? 0;
                $milestoneData['status'] = $milestoneData['status'] ?? 'Not Started';
                
                $milestone = ProjectMilestone::create($milestoneData);
                $milestone->updateStatusFromProgress()->save();
                $createdMilestones[] = $milestone;
            }
        });

        return response()->json([
            'success' => true,
            'message' => count($createdMilestones) . ' milestones added successfully.',
            'milestones' => $createdMilestones,
            'project_progress' => $project->getProgressPercentage(),
        ]);
    }

    /**
     * Reorder milestones (deprecated - sort_order removed)
     */
    public function reorder(Request $request, $projectId)
    {
        // Reordering is no longer supported as sort_order column was removed
        // Milestones are now ordered by planned_start_date
        return response()->json([
            'success' => true,
            'message' => 'Milestones are ordered by planned start date.',
        ]);
    }

    /**
     * Get timeline data for Gantt chart visualization
     */
    public function timeline($projectId)
    {
        $project = Project::with('milestones')->findOrFail($projectId);
        
        $timelineData = $project->milestones->map(function ($milestone) {
            return [
                'id' => $milestone->id,
                'name' => $milestone->name,
                'weight' => $milestone->weight_percentage,
                'progress' => $milestone->progress_percentage,
                'status' => $milestone->status,
                'status_color' => $milestone->status_color,
                'planned_start' => $milestone->planned_start_date?->format('Y-m-d'),
                'planned_end' => $milestone->planned_end_date?->format('Y-m-d'),
                'actual_start' => $milestone->actual_start_date?->format('Y-m-d'),
                'actual_end' => $milestone->actual_end_date?->format('Y-m-d'),
                'planned_duration' => $milestone->planned_duration,
                'actual_duration' => $milestone->actual_duration,
                'is_delayed' => $milestone->is_delayed,
                'delay_days' => $milestone->delay_days,
            ];
        });

        return response()->json([
            'success' => true,
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'progress' => $project->getProgressPercentage(),
                'planned_start' => $project->planned_start_date?->format('Y-m-d'),
                'planned_end' => $project->planned_end_date?->format('Y-m-d'),
            ],
            'timeline' => $timelineData,
            'summary' => [
                'total_milestones' => $project->milestones->count(),
                'completed' => $project->milestones->where('status', 'Completed')->count(),
                'in_progress' => $project->milestones->where('status', 'In Progress')->count(),
                'delayed' => $project->milestones->where('status', 'Delayed')->count(),
                'total_weight' => $project->milestones->sum('weight_percentage'),
            ],
        ]);
    }
}

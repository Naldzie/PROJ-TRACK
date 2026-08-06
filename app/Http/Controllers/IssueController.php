<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Issue;
use App\Models\ProjectBaselineSchedule;
use App\Notifications\IssueReported;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * IssueController
 * 
 * Handles all Issue management operations:
 * - Listing issues for a project
 * - Creating new issues (Admin/Staff only)
 * - Updating issues (Admin/Staff only)
 * - Deleting issues (Admin/Staff only)
 * - Contractors have read-only access
 */
class IssueController extends Controller
{
    /**
     * Get all issues for a project
     */
    public function getIssues(Project $project)
    {
        try {
            $issues = $project->issues()
                ->with(['baselineSchedule', 'attachments', 'sourceRisk'])
                ->get()
                ->map(function($issue) {
                    return [
                        'id' => $issue->id,
                        'issue_name' => $issue->issue_name,
                        'description' => $issue->description,
                        'status' => $issue->status,
                        'issue_type' => $issue->issue_type,
                        'severity' => $issue->severity,
                        'resolved_at' => $issue->resolved_at ? $issue->resolved_at->format('M d, Y') : null,
                        'resolution_notes' => $issue->resolution_notes,
                        'baseline_schedule_id' => $issue->baseline_schedule_id,
                        'month_label' => $issue->baselineSchedule?->month_label,
                        'month_display' => $issue->baselineSchedule?->month_display,
                        'source_risk_id' => $issue->source_risk_id,
                        'source_risk_name' => $issue->sourceRisk?->risk_name,
                        'created_at' => $issue->created_at->format('M d, Y'),
                        'updated_at' => $issue->updated_at->format('M d, Y'),
                        'attachments' => $issue->attachments->map(function ($attachment) {
                            return [
                                'id' => $attachment->id,
                                'original_name' => $attachment->original_name,
                                'context' => $attachment->context,
                                'file_url' => $attachment->file_url,
                                'display_size' => $attachment->display_size,
                                'uploaded_by' => $attachment->uploaded_by,
                                'created_at' => $attachment->created_at->format('M d, Y H:i'),
                            ];
                        }),
                    ];
                });

            return response()->json([
                'success' => true,
                'issues' => $issues,
                'count' => $issues->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching issues: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new issue (Admin/Staff only)
     */
    public function store(Request $request, Project $project)
    {
        try {
            // Check authorization - only admin and staff can create issues
            $user = Auth::user();
            if (!in_array($user->role, ['admin', 'staff'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Only admin and staff can create issues'
                ], 403);
            }

            // Validate input
            $validated = $request->validate([
                'issue_name' => 'required|string|max:255',
                'description' => 'required|string|max:1000',
                'status' => 'required|in:Open,In Progress,Resolved,Closed',
                'issue_type' => 'nullable|string|in:material,contractor,weather,design,regulatory,financial,other',
                'severity' => 'nullable|string|in:Low,Medium,High,Critical',
                'baseline_schedule_id' => 'nullable|integer|exists:project_baseline_schedules,id',
                'resolution_notes' => 'required_if:status,Resolved|required_if:status,Closed|nullable|string|max:5000'
            ]);

            // Guard: the selected baseline month must belong to this project
            if (!empty($validated['baseline_schedule_id'])) {
                $baselineExists = ProjectBaselineSchedule::where('id', $validated['baseline_schedule_id'])
                    ->where('project_id', $project->id)
                    ->exists();
                if (!$baselineExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected baseline month does not belong to this project.'
                    ], 422);
                }
            }

            // Auto-set resolved_at if status is Resolved or Closed
            $data = $validated;
            if (in_array($data['status'], ['Resolved', 'Closed'])) {
                $data['resolved_at'] = now();
            }

            // Create issue
            $issue = Issue::create(array_merge($data, [
                'project_id' => $project->id,
            ]));

            // Audit the creation
            ActivityLog::create([
                'user_id'     => $user->id,
                'project_id'  => $project->id,
                'action'      => 'Create Issue',
                'description' => $user->name . ' created issue "' . $issue->issue_name . '" for project "' . $project->title . '".',
                'ip_address'  => $request->ip(),
            ]);

            // Notify admins + the project's contractor about the new issue
            // (all severities — user confirmed).
            $service = app(NotificationService::class);
            $recipients = collect($service->admins());
            $contractorUser = $service->contractorUserFor($project);
            if ($contractorUser) {
                $recipients->push($contractorUser);
            }
            $service->notifyMany($recipients->unique('id'), new IssueReported(
                $project->id,
                $project->title,
                $issue->id,
                $issue->issue_name,
                $issue->severity ?? 'Low'
            ));

            return response()->json([
                'success' => true,
                'message' => 'Issue created successfully',
                'issue' => [
                    'id' => $issue->id,
                    'issue_name' => $issue->issue_name,
                    'description' => $issue->description,
                    'status' => $issue->status,
                    'issue_type' => $issue->issue_type,
                    'severity' => $issue->severity,
                    'resolved_at' => $issue->resolved_at ? $issue->resolved_at->format('M d, Y') : null,
                    'resolution_notes' => $issue->resolution_notes,
                    'baseline_schedule_id' => $issue->baseline_schedule_id,
                    'created_at' => $issue->created_at->format('M d, Y'),
                    'updated_at' => $issue->updated_at->format('M d, Y'),
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Issue creation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating issue: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an issue (Admin/Staff only)
     */
    public function update(Request $request, Project $project, Issue $issue)
    {
        try {
            // Check authorization
            $user = Auth::user();
            if (!in_array($user->role, ['admin', 'staff'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Only admin and staff can update issues'
                ], 403);
            }

            // Verify issue belongs to project
            if ($issue->project_id !== $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Issue not found in this project'
                ], 404);
            }

            // Validate input
            $validated = $request->validate([
                'issue_name' => 'required|string|max:255',
                'description' => 'required|string|max:1000',
                'status' => 'required|in:Open,In Progress,Resolved,Closed',
                'issue_type' => 'nullable|string|in:material,contractor,weather,design,regulatory,financial,other',
                'severity' => 'nullable|string|in:Low,Medium,High,Critical',
                'baseline_schedule_id' => 'nullable|integer|exists:project_baseline_schedules,id',
                'resolution_notes' => 'required_if:status,Resolved|required_if:status,Closed|nullable|string|max:5000'
            ]);

            // Guard: the selected baseline month must belong to this project
            if (!empty($validated['baseline_schedule_id'])) {
                $baselineExists = ProjectBaselineSchedule::where('id', $validated['baseline_schedule_id'])
                    ->where('project_id', $project->id)
                    ->exists();
                if (!$baselineExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected baseline month does not belong to this project.'
                    ], 422);
                }
            }

            // Auto-manage resolved_at based on status transitions
            $data = $validated;
            $newStatus = $data['status'];
            $oldStatus = $issue->status;

            if (in_array($newStatus, ['Resolved', 'Closed'])) {
                // Only set resolved_at if it wasn't already resolved (preserve original resolution date)
                if (!in_array($oldStatus, ['Resolved', 'Closed'])) {
                    $data['resolved_at'] = now();
                }
            } elseif (in_array($oldStatus, ['Resolved', 'Closed'])) {
                // Moving back to Open/In Progress — clear the resolution date
                $data['resolved_at'] = null;
            }

            // Update issue
            $issue->update($data);

            // Audit the update
            ActivityLog::create([
                'user_id'     => $user->id,
                'project_id'  => $project->id,
                'action'      => 'Update Issue',
                'description' => $user->name . ' updated issue "' . $issue->issue_name . '" for project "' . $project->title . '".',
                'ip_address'  => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Issue updated successfully',
                'issue' => [
                    'id' => $issue->id,
                    'issue_name' => $issue->issue_name,
                    'description' => $issue->description,
                    'status' => $issue->status,
                    'issue_type' => $issue->issue_type,
                    'severity' => $issue->severity,
                    'resolved_at' => $issue->resolved_at ? $issue->resolved_at->format('M d, Y') : null,
                    'resolution_notes' => $issue->resolution_notes,
                    'baseline_schedule_id' => $issue->baseline_schedule_id,
                    'created_at' => $issue->created_at->format('M d, Y'),
                    'updated_at' => $issue->updated_at->format('M d, Y'),
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Issue update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating issue: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an issue (Admin/Staff only)
     */
    public function destroy(Project $project, Issue $issue)
    {
        try {
            // Check authorization
            $user = Auth::user();
            if (!in_array($user->role, ['admin', 'staff'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Only admin and staff can delete issues'
                ], 403);
            }

            // Verify issue belongs to project
            if ($issue->project_id !== $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Issue not found in this project'
                ], 404);
            }

            // Capture details before deleting — model is gone after delete()
            $issueName  = $issue->issue_name;
            $projectTitle = $project->title;

            // If this issue was created by converting a risk, revert that risk
            // to Open and clear the link so the risk is preserved and can be
            // re-converted or mitigated (forward delete-link guard).
            if ($issue->source_risk_id) {
                $sourceRisk = $issue->sourceRisk;
                if ($sourceRisk) {
                    $sourceRisk->update([
                        'status'                => 'Open',
                        'converted_to_issue_id' => null,
                    ]);
                }
            }

            // Remove evidence attachments (rows + physical files via the model's deleting hook)
            foreach ($issue->attachments as $attachment) {
                $attachment->delete();
            }

            $issue->delete();

            // Audit the deletion
            ActivityLog::create([
                'user_id'     => $user->id,
                'project_id'  => $project->id,
                'action'      => 'Delete Issue',
                'description' => $user->name . ' deleted issue "' . $issueName . '" from project "' . $projectTitle . '".',
                'ip_address'  => request()->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Issue deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Issue deletion error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting issue: ' . $e->getMessage()
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\Risk;
use App\Notifications\IssueReported;
use App\Notifications\RiskReported;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * RiskController
 *
 * Handles Risk Register (gap #20) operations:
 * - Listing risks for a project
 * - Creating risks (Admin/Staff only)
 * - Updating risks (Admin/Staff only)
 * - Deleting risks (Admin/Staff only)
 * - Contractors have read-only access (mirrors IssueController)
 */
class RiskController extends Controller
{
    /**
     * Get all risks for a project (all roles; contractor read-only).
     */
    public function getRisks(Project $project)
    {
        try {
            $risks = $project->risks()
                ->with(['baselineSchedule', 'convertedToIssue', 'attachments'])
                ->get()
                ->map(function (Risk $risk) {
                    return $this->riskToJson($risk);
                });

            return response()->json([
                'success' => true,
                'risks'   => $risks,
                'count'   => $risks->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Risk list error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching risks: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a new risk (Admin/Staff only). High-level risks notify
     * admins + the project's contractor.
     */
    public function store(Request $request, Project $project)
    {
        try {
            $user = Auth::user();
            if ($user->role !== 'admin' && !$project->isAssignedTo($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: You are not assigned to this project',
                ], 403);
            }

            $validated = $request->validate([
                'risk_name'      => 'required|string|max:255',
                'description'    => 'nullable|string|max:5000',
                'probability'    => 'required|in:Low,Medium,High',
                'impact'         => 'required|in:Low,Medium,High',
                'mitigation_plan' => 'nullable|string|max:5000',
                'status'         => 'required|in:Open,Mitigated,Closed',
                'identified_at'  => 'nullable|date',
                'baseline_schedule_id' => 'nullable|integer|exists:project_baseline_schedules,id',
            ]);

            // Guard: the selected baseline month must belong to this project
            if (!empty($validated['baseline_schedule_id'])) {
                $baselineExists = ProjectBaselineSchedule::where('id', $validated['baseline_schedule_id'])
                    ->where('project_id', $project->id)
                    ->exists();
                if (!$baselineExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected baseline month does not belong to this project.',
                    ], 422);
                }
            }

            $data = $this->applyLifecycleTimestamps($validated);

            // Compute risk_score (1-9) from probability × impact
            $data['risk_score'] = Risk::computeScore($data['probability'], $data['impact']);

            // Create the risk record
            $risk = Risk::create(array_merge($data, [
                'project_id' => $project->id,
            ]));

            // Audit the creation
            ActivityLog::create([
                'user_id'     => $user->id,
                'project_id'  => $project->id,
                'action'      => 'Create Risk',
                'description' => $user->name . ' created risk "' . $risk->risk_name . '" for project "' . $project->title . '".',
                'ip_address'  => $request->ip(),
            ]);

            // Notify admins + the project's contractor for HIGH-level risks only
            if ($risk->risk_level === 'High') {
                $service = app(NotificationService::class);
                $recipients = collect($service->admins());
                $contractorUser = $service->contractorUserFor($project);
                if ($contractorUser) {
                    $recipients->push($contractorUser);
                }
                $service->notifyMany($recipients->unique('id'), new RiskReported(
                    $project->id,
                    $project->title,
                    $risk->id,
                    $risk->risk_name
                ));
            }

            return response()->json([
                'success' => true,
                'message' => 'Risk created successfully',
                'risk'    => $this->riskToJson($risk),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Risk creation error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error creating risk: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a risk (Admin/Staff only). Recomputed risk_score and
     * lifecycle timestamps (mitigated_at / closed_at) on status change.
     */
    public function update(Request $request, Project $project, Risk $risk)
    {
        try {
            $user = Auth::user();
            if ($user->role !== 'admin' && !$project->isAssignedTo($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: You are not assigned to this project',
                ], 403);
            }

            // Verify risk belongs to project
            if ($risk->project_id !== $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Risk not found in this project',
                ], 404);
            }

            $validated = $request->validate([
                'risk_name'      => 'required|string|max:255',
                'description'    => 'nullable|string|max:5000',
                'probability'    => 'required|in:Low,Medium,High',
                'impact'         => 'required|in:Low,Medium,High',
                'mitigation_plan' => 'nullable|string|max:5000',
                'status'         => 'required|in:Open,Mitigated,Closed,Realized',
                'identified_at'  => 'nullable|date',
                'baseline_schedule_id' => 'nullable|integer|exists:project_baseline_schedules,id',
            ]);

            // Guard: the selected baseline month must belong to this project
            if (!empty($validated['baseline_schedule_id'])) {
                $baselineExists = ProjectBaselineSchedule::where('id', $validated['baseline_schedule_id'])
                    ->where('project_id', $project->id)
                    ->exists();
                if (!$baselineExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected baseline month does not belong to this project.',
                    ], 422);
                }
            }

            $data = $this->applyLifecycleTimestamps($validated, $risk);
            $data['risk_score'] = Risk::computeScore($data['probability'], $data['impact']);

            $risk->update($data);

            // Audit the update
            ActivityLog::create([
                'user_id'     => $user->id,
                'project_id'  => $project->id,
                'action'      => 'Update Risk',
                'description' => $user->name . ' updated risk "' . $risk->risk_name . '" for project "' . $project->title . '".',
                'ip_address'  => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Risk updated successfully',
                'risk'    => $this->riskToJson($risk),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Risk update error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error updating risk: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a risk (Admin/Staff only).
     */
    public function destroy(Project $project, Risk $risk)
    {
        try {
            $user = Auth::user();
            if ($user->role !== 'admin' && !$project->isAssignedTo($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: You are not assigned to this project',
                ], 403);
            }

            // Verify risk belongs to project
            if ($risk->project_id !== $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Risk not found in this project',
                ], 404);
            }

            // Guard: a Realized risk is linked to an issue — prevent dangling
            // issue.source_risk_id by requiring the issue to be deleted first.
            if ($risk->converted_to_issue_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'This risk was converted to an issue. Delete the issue first to remove both records.',
                ], 422);
            }

            // Capture details before deleting — model is gone after delete()
            $riskName = $risk->risk_name;
            $projectTitle = $project->title;

            $risk->delete();

            // Audit the deletion
            ActivityLog::create([
                'user_id'     => $user->id,
                'project_id'  => $project->id,
                'action'      => 'Delete Risk',
                'description' => $user->name . ' deleted risk "' . $riskName . '" from project "' . $projectTitle . '".',
                'ip_address'  => request()->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Risk deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Risk deletion error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error deleting risk: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Convert a risk into an issue when it materializes (Admin/Staff only).
     *
     * Creates a new Issue pre-filled from the risk (name, description, severity
     * mapped from risk level, baseline month carried over), marks the risk as
     * Realized, links the two records in both directions, transfers the risk's
     * evidence attachments to the new issue (relabelled context 'issue'), and
     * fires the normal IssueReported notification (admins + contractor).
     */
    public function convertToIssue(Request $request, Project $project, Risk $risk)
    {
        try {
            $user = Auth::user();
            if ($user->role !== 'admin' && !$project->isAssignedTo($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: You are not assigned to this project',
                ], 403);
            }

            // Guard: risk belongs to project
            if ($risk->project_id !== $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Risk not found in this project',
                ], 404);
            }

            // Guard: only Open/Mitigated risks can be converted
            if (in_array($risk->status, ['Realized', 'Closed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This risk is already ' . $risk->status . ' and cannot be converted again.',
                ], 422);
            }

            // Validate the pre-filled, editable issue fields sent from the modal
            $validated = $request->validate([
                'issue_name'   => 'required|string|max:255',
                'description'  => 'nullable|string|max:5000',
                'issue_type'   => 'nullable|string|in:material,contractor,weather,design,regulatory,financial,other',
                'severity'     => 'nullable|string|in:Low,Medium,High,Critical',
                'baseline_schedule_id' => 'nullable|integer|exists:project_baseline_schedules,id',
            ]);

            // Guard: the selected baseline month must belong to this project
            if (!empty($validated['baseline_schedule_id'])) {
                $baselineExists = ProjectBaselineSchedule::where('id', $validated['baseline_schedule_id'])
                    ->where('project_id', $project->id)
                    ->exists();
                if (!$baselineExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected baseline month does not belong to this project.',
                    ], 422);
                }
            }

            // Build the issue from the modal values, falling back to the risk
            $issue = Issue::create([
                'project_id'           => $project->id,
                'issue_name'           => $validated['issue_name'],
                'description'          => $validated['description'] ?? 'Risk "' . $risk->risk_name . '" materialized and was converted into an issue.',
                'status'               => 'Open',
                'issue_type'           => $validated['issue_type'] ?? 'other',
                'severity'             => $validated['severity'] ?? $this->severityFromRiskLevel($risk->risk_level),
                'baseline_schedule_id' => $validated['baseline_schedule_id'] ?? $risk->baseline_schedule_id,
                'source_risk_id'       => $risk->id,
            ]);

            // Link the risk to the issue and mark it Realized
            $risk->update([
                'status'               => 'Realized',
                'converted_to_issue_id'=> $issue->id,
            ]);

            // Transfer risk evidence attachments to the new issue (relabel 'issue')
            // and stamp each with its provenance (risk_origin_id) so that, if the
            // converted issue is later deleted, only these originally-from-the-risk
            // files are returned to the reverted risk. Files uploaded directly to
            // the issue (e.g. via the convert modal) stay unstamped and are
            // deleted with the issue.
            foreach ($risk->attachments as $attachment) {
                $attachment->update([
                    'attachable_type' => Issue::class,
                    'attachable_id'   => $issue->id,
                    'context'         => 'issue',
                    'risk_origin_id'  => $risk->id,
                ]);
            }

            // Audit the conversion
            ActivityLog::create([
                'user_id'     => $user->id,
                'project_id'  => $project->id,
                'action'      => 'Convert Risk to Issue',
                'description' => $user->name . ' converted risk "' . $risk->risk_name . '" to issue "' . $issue->issue_name . '" for project "' . $project->title . '".',
                'ip_address'  => request()->ip(),
            ]);

            // Notify admins + the project's contractor (same as IssueController::store)
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
                'message' => 'Risk converted to issue successfully',
                'issue'   => $issue,
                'risk'    => $this->riskToJson($risk),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Risk conversion error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error converting risk: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Map a risk level (Low/Medium/High) to an issue severity.
     * Called by: convertToIssue()
     */
    private function severityFromRiskLevel(string $level): string
    {
        return in_array($level, ['Low', 'Medium', 'High']) ? $level : 'Low';
    }

    /**
     * Shape a Risk model into the JSON payload consumed by the UI.
     *
     * Called by: getRisks(), store(), update(), convertToIssue()
     */
    private function riskToJson(Risk $risk): array
    {
        return [
            'id'             => $risk->id,
            'risk_name'      => $risk->risk_name,
            'description'    => $risk->description,
            'probability'    => $risk->probability,
            'impact'         => $risk->impact,
            'risk_score'     => $risk->risk_score,
            'risk_level'     => $risk->risk_level,
            'mitigation_plan' => $risk->mitigation_plan,
            'status'         => $risk->status,
            'identified_at'  => $risk->identified_at ? $risk->identified_at->format('M d, Y') : null,
            'mitigated_at'   => $risk->mitigated_at ? $risk->mitigated_at->format('M d, Y') : null,
            'closed_at'      => $risk->closed_at ? $risk->closed_at->format('M d, Y') : null,
            'baseline_schedule_id' => $risk->baseline_schedule_id,
            'baseline_label' => $risk->baselineSchedule?->month_display,
            'converted_to_issue_id' => $risk->converted_to_issue_id,
            'converted_issue_label' => $risk->convertedToIssue ? '#' . $risk->convertedToIssue->id . ' ' . $risk->convertedToIssue->issue_name : null,
            'attachments'    => $risk->attachments->map(function ($attachment) {
                return [
                    'id'            => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'context'       => $attachment->context,
                    'file_url'      => $attachment->file_url,
                    'display_size'  => $attachment->display_size,
                    'uploaded_by'   => $attachment->uploaded_by,
                    'created_at'    => $attachment->created_at->format('M d, Y H:i'),
                ];
            }),
            'created_at'     => $risk->created_at->format('M d, Y'),
            'updated_at'     => $risk->updated_at->format('M d, Y'),
        ];
    }

    /**
     * Set/clear mitigated_at and closed_at based on the incoming status.
     * On update, preserves an existing lifecycle timestamp (does not
     * overwrite a previously-stamped mitigation/closure date).
     *
     * Called by: store(), update()
     *
     * @param  array  $data  validated input
     * @param  Risk|null  $existing  existing model on update (null on create)
     * @return array  input with lifecycle timestamps applied
     */
    private function applyLifecycleTimestamps(array $data, ?Risk $existing = null): array
    {
        $status = $data['status'];

        if ($status === 'Mitigated') {
            // Stamp mitigated_at only once (preserve the original mitigation date)
            $data['mitigated_at'] = $data['mitigated_at'] ?? ($existing?->mitigated_at ?? now());
            $data['closed_at']    = null;
        } elseif ($status === 'Closed') {
            $data['mitigated_at'] = $data['mitigated_at'] ?? $existing?->mitigated_at;
            $data['closed_at']    = $data['closed_at'] ?? ($existing?->closed_at ?? now());
        } elseif ($status === 'Realized') {
            // A Realized risk is the product of conversion; keep any earlier
            // lifecycle stamps (the risk may have been mitigated first).
            $data['mitigated_at'] = $data['mitigated_at'] ?? $existing?->mitigated_at;
            $data['closed_at']    = $data['closed_at'] ?? $existing?->closed_at;
        } else {
            // Returning to Open clears both lifecycle stamps
            $data['mitigated_at'] = null;
            $data['closed_at']    = null;
        }

        return $data;
    }
}

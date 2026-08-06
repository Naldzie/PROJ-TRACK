<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Risk;
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
            $risks = $project->risks()->get()->map(function (Risk $risk) {
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
            // Authorization — only admin and staff can create risks
            $user = Auth::user();
            if (!in_array($user->role, ['admin', 'staff'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Only admin and staff can create risks',
                ], 403);
            }

            $validated = $request->validate([
                'risk_name'      => 'required|string|max:255',
                'description'    => 'nullable|string|max:5000',
                'probability'    => 'required|in:Low,Medium,High',
                'impact'         => 'required|in:Low,Medium,High',
                'mitigation_plan' => 'nullable|string|max:5000',
                'owner'          => 'nullable|string|max:255',
                'status'         => 'required|in:Open,Mitigated,Closed',
                'identified_at'  => 'nullable|date',
            ]);

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
            // Authorization — only admin and staff can update risks
            $user = Auth::user();
            if (!in_array($user->role, ['admin', 'staff'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Only admin and staff can update risks',
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
                'owner'          => 'nullable|string|max:255',
                'status'         => 'required|in:Open,Mitigated,Closed',
                'identified_at'  => 'nullable|date',
            ]);

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
            // Authorization — only admin and staff can delete risks
            $user = Auth::user();
            if (!in_array($user->role, ['admin', 'staff'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Only admin and staff can delete risks',
                ], 403);
            }

            // Verify risk belongs to project
            if ($risk->project_id !== $project->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Risk not found in this project',
                ], 404);
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
     * Shape a Risk model into the JSON payload consumed by the UI.
     *
     * Called by: getRisks(), store(), update()
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
            'owner'          => $risk->owner,
            'status'         => $risk->status,
            'identified_at'  => $risk->identified_at ? $risk->identified_at->format('M d, Y') : null,
            'mitigated_at'   => $risk->mitigated_at ? $risk->mitigated_at->format('M d, Y') : null,
            'closed_at'      => $risk->closed_at ? $risk->closed_at->format('M d, Y') : null,
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
        } else {
            // Returning to Open clears both lifecycle stamps
            $data['mitigated_at'] = null;
            $data['closed_at']    = null;
        }

        return $data;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectExtension;
use App\Models\User;
use App\Notifications\ExtensionStatusChanged;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProjectExtensionController extends Controller
{
    /**
     * Store a new project extension.
     *
     * ADMIN: created and auto-approved immediately (unchanged behavior) —
     *        project's planned_end_date/extension_count updated right away.
     * STAFF: created as a PENDING request only. Project is NOT updated
     *        until an admin approves it via update(). Only one pending
     *        extension is allowed per project at a time.
     */
    public function store(Request $request, Project $project)
    {
        try {
            $validated = $this->validateExtension($request, $project);

            $isAdmin = auth()->user()->role === 'admin';

            // Only one pending extension allowed per project at a time.
            $existingPending = ProjectExtension::where('project_id', $project->id)
                ->where('status', 'pending')
                ->exists();

            if ($existingPending) {
                return response()->json([
                    'success' => false,
                    'message' => 'This project already has a pending extension request. Edit that request instead of creating a new one.',
                ], 422);
            }

            DB::beginTransaction();

            $maxExtensionNumber = DB::table('project_extensions')
                ->where('project_id', $project->id)
                ->max('extension_number') ?? 0;
            $nextExtensionNumber = $maxExtensionNumber + 1;

            $originalEndDate = $project->planned_end_date;
            $newEndDate = Carbon::parse($validated['new_end_date']);
            $durationDays = $originalEndDate->diffInDays($newEndDate);

            $extension = ProjectExtension::create([
                'project_id' => $project->id,
                'extension_number' => $nextExtensionNumber,
                'original_end_date' => $originalEndDate,
                'new_end_date' => $newEndDate,
                'duration_days' => $durationDays,
                'reason' => $validated['reason'],
                // Admin submissions are auto-approved; staff submissions
                // start pending and wait for an admin to approve them.
                'status' => $isAdmin ? 'approved' : 'pending',
                'approved_by_user_id' => $isAdmin ? auth()->user()->id : null,
                'approved_at' => $isAdmin ? now() : null,
                // Track who submitted the request so approval/rejection
                // notices can be targeted at the requester.
                'created_by_user_id' => auth()->id(),
            ]);

            // Only push the change to the project itself once the
            // extension is actually approved. For admin this happens
            // immediately; for staff it happens later in update()/approve.
            if ($isAdmin) {
                $project->update([
                    'planned_end_date' => $newEndDate,
                    'extension_count' => $nextExtensionNumber,
                ]);
            }

            DB::commit();

            // Audit the submission. Admin submissions are auto-approved;
            // staff submissions sit pending until an admin approves them.
            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => $isAdmin ? 'Approve Extension' : 'Request Extension',
                'description' => auth()->user()->name . ($isAdmin
                        ? " approved extension #{$nextExtensionNumber} for project: {$project->title}"
                        : " requested extension #{$nextExtensionNumber} ({$durationDays} days) for project: {$project->title}"),
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            // Notify: staff submissions go to admins; admin auto-approvals go to
            // the project contractor (who is affected by the new deadline).
            $this->notifyExtension(
                $project,
                $extension,
                $isAdmin ? ExtensionStatusChanged::APPROVED : ExtensionStatusChanged::SUBMITTED
            );

            return response()->json([
                'success' => true,
                'message' => $isAdmin
                    ? "Extension #{$nextExtensionNumber} created and approved"
                    : "Extension #{$nextExtensionNumber} submitted for admin approval",
                'extension' => $this->formatExtension($extension),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Extension store error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error creating extension: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all extensions for a project (visible to admin, staff, AND
     * contractor — read access already exists for everyone; only write
     * access is role-restricted).
     */
    public function index(Project $project)
    {
        try {
            $extensions = $project->extensions()
                ->with('approver')
                ->orderBy('extension_number', 'asc')
                ->get()
                ->map(fn ($ext) => $this->formatExtension($ext));

            return response()->json([
                'success' => true,
                'extensions' => $extensions,
                'total' => $extensions->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Extension index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching extensions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single extension.
     */
    public function show(Project $project, ProjectExtension $extension)
    {
        try {
            if ($extension->project_id !== $project->id) {
                return response()->json(['success' => false, 'message' => 'Extension not found'], 404);
            }

            return response()->json([
                'success' => true,
                'extension' => $this->formatExtension($extension),
            ]);
        } catch (\Exception $e) {
            \Log::error('Extension show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching extension: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an extension. Behavior branches by role:
     *
     * STAFF: may edit ONLY their own extension while it is 'pending' or
     *        'rejected'. Can change new_end_date/reason. Can NEVER set
     *        status directly — that field is stripped from their payload
     *        regardless of what's submitted. Editing a 'rejected'
     *        extension resets it to 'pending' for re-review.
     *
     * ADMIN: may edit any extension's details AND status. Transitioning
     *        status -> 'approved' is the moment planned_end_date /
     *        extension_count get applied to the project. Transitioning
     *        to 'rejected' leaves the project untouched (extension stays
     *        visible in history so staff can revise and resubmit it).
     */
    public function update(Request $request, Project $project, ProjectExtension $extension)
    {
        try {
            if ($extension->project_id !== $project->id) {
                return response()->json(['success' => false, 'message' => 'Extension not found'], 404);
            }

            $user = auth()->user();
            $isAdmin = $user->role === 'admin';

            if (!$isAdmin) {
                // Staff guard: only their own extension, and only while
                // it's still pending or rejected (not yet approved).
                if ((int) $extension->approved_by_user_id === (int) $user->id && $extension->isApproved()) {
                    // Defensive: an already-approved extension is locked
                    // for staff regardless of who originally submitted it.
                }

                if (!in_array($extension->status, ['pending', 'rejected'], true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This extension has already been approved and can no longer be edited.',
                    ], 403);
                }

                $validated = $request->validate([
                    'new_end_date' => [
                        'sometimes', 'required', 'date_format:Y-m-d',
                        'after:' . $project->planned_end_date->format('Y-m-d'),
                        'before_or_equal:2030-12-31',
                    ],
                    'reason' => 'sometimes|required|string|min:5|max:500',
                ]);

                // status is intentionally never read from the staff payload.
                $wasRejected = $extension->isRejected();

                DB::beginTransaction();

                if (isset($validated['new_end_date'])) {
                    $newEndDate = Carbon::parse($validated['new_end_date']);
                    $extension->new_end_date = $newEndDate;
                    $extension->duration_days = $extension->original_end_date->diffInDays($newEndDate);
                }
                if (isset($validated['reason'])) {
                    $extension->reason = $validated['reason'];
                }

                if ($wasRejected) {
                    // Resubmitting a rejected request puts it back to pending.
                    // Clear the old rejection reason — it applied to the
                    // PREVIOUS version of this request, not the revised one.
                    $extension->status = 'pending';
                    $extension->approved_by_user_id = null;
                    $extension->approved_at = null;
                    $extension->rejection_reason = null;
                }

                $extension->save();
                DB::commit();

                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'project_id' => $project->id,
                    'action' => $wasRejected ? 'Resubmit Extension' : 'Update Extension',
                    'description' => auth()->user()->name . " " . ($wasRejected
                            ? "resubmitted extension #{$extension->extension_number} for project: {$project->title}"
                            : "updated extension #{$extension->extension_number} for project: {$project->title}"),
                    'ip_address' => request()->ip(),
                    'visibility' => 'all',
                ]);

                // A resubmitted (previously rejected) request goes back to
                // pending — re-notify the admins for review.
                if ($wasRejected) {
                    $this->notifyExtension($project, $extension, ExtensionStatusChanged::SUBMITTED);
                }

                return response()->json([
                    'success' => true,
                    'message' => $wasRejected
                        ? 'Extension request revised and resubmitted for approval'
                        : 'Extension request updated',
                    'extension' => $this->formatExtension($extension),
                ]);
            }

            // --- Admin path ---
            $validated = $request->validate([
                'new_end_date' => [
                    'sometimes', 'required', 'date_format:Y-m-d',
                    'before_or_equal:2030-12-31',
                ],
                'reason' => 'sometimes|required|string|min:5|max:500',
                'status' => 'sometimes|required|in:pending,approved,rejected',
                // Required ONLY when the admin is setting status to
                // rejected — staff need this to know what to fix before
                // resubmitting via the edit-mode flow.
                'rejection_reason' => 'required_if:status,rejected|nullable|string|min:5|max:500',
            ]);

            DB::beginTransaction();

            $transitioningToApproved = isset($validated['status'])
                && $validated['status'] === 'approved'
                && !$extension->isApproved();

            if (isset($validated['new_end_date'])) {
                $newEndDate = Carbon::parse($validated['new_end_date']);
                $extension->new_end_date = $newEndDate;
                $extension->duration_days = $extension->original_end_date->diffInDays($newEndDate);
            }
            if (isset($validated['reason'])) {
                $extension->reason = $validated['reason'];
            }
            if (isset($validated['status'])) {
                $extension->status = $validated['status'];
                if ($validated['status'] === 'approved') {
                    $extension->approved_by_user_id = $user->id;
                    $extension->approved_at = now();
                    // Approving clears any stale rejection reason from a
                    // PRIOR rejection cycle on this same extension.
                    $extension->rejection_reason = null;
                } elseif ($validated['status'] === 'rejected') {
                    // Keep approver fields null/untouched for a rejection —
                    // it was never approved.
                    $extension->approved_by_user_id = null;
                    $extension->approved_at = null;
                    $extension->rejection_reason = $validated['rejection_reason'];
                } elseif ($validated['status'] === 'pending') {
                    // Manually reverting to pending (rare, but keep state
                    // clean) — clear both approval and rejection traces.
                    $extension->approved_by_user_id = null;
                    $extension->approved_at = null;
                    $extension->rejection_reason = null;
                }
            }

            $extension->save();

            // The moment status becomes 'approved' is when the project's
            // live deadline/extension_count actually move.
            if ($transitioningToApproved) {
                $project->update([
                    'planned_end_date' => $extension->new_end_date,
                    'extension_count' => $extension->extension_number,
                ]);
            }

            DB::commit();

            $logAction = 'Update Extension';
            if ($transitioningToApproved) {
                $logAction = 'Approve Extension';
            } elseif (($validated['status'] ?? null) === 'rejected') {
                $logAction = 'Reject Extension';
            }

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => $logAction,
                'description' => auth()->user()->name . " {$logAction} for project: {$project->title}"
                    . (($validated['status'] ?? null) === 'rejected' && !empty($validated['rejection_reason'])
                        ? ' — ' . $validated['rejection_reason']
                        : ''),
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            // Notify on approve/reject: the requester (created_by_user_id) + the
            // project contractor. Rejection carries the reason for resubmission.
            if ($transitioningToApproved) {
                $this->notifyExtension($project, $extension, ExtensionStatusChanged::APPROVED);
            } elseif (($validated['status'] ?? null) === 'rejected') {
                $this->notifyExtension($project, $extension, ExtensionStatusChanged::REJECTED);
            }

            return response()->json([
                'success' => true,
                'message' => 'Extension updated',
                'extension' => $this->formatExtension($extension),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Extension update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating extension: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an extension (only if not the active/approved one currently
     * in effect — unchanged guard from before).
     */
    public function destroy(Request $request, Project $project, ProjectExtension $extension)
    {
        try {
            if ($extension->project_id !== $project->id) {
                return response()->json(['success' => false, 'message' => 'Extension not found'], 404);
            }

            // Same fix as extensions-section.blade.php's Current Deadline
            // card: reorder() clears any default ordering baked into the
            // extensions() relationship before applying descending order,
            // rather than risking a silently-ignored second orderBy.
            $latestExtension = $project->extensions()
                ->reorder('extension_number', 'desc')
                ->first();

            if ($extension->id === $latestExtension->id && $extension->isApproved()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete the active approved extension. Create a new one to replace it.',
                ], 400);
            }

            $extensionNumber = $extension->extension_number;
            $extension->delete();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Delete Extension',
                'description' => auth()->user()->name . " deleted extension #{$extensionNumber} from project: {$project->title}",
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Extension #{$extensionNumber} deleted",
            ]);

        } catch (\Exception $e) {
            \Log::error('Extension destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting extension: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validation logic for a brand-new extension request.
     */
    private function validateExtension(Request $request, Project $project): array
    {
        return $request->validate([
            'new_end_date' => [
                'required',
                'date_format:Y-m-d',
                'after:' . $project->planned_end_date->format('Y-m-d'),
                'before_or_equal:2030-12-31',
            ],
            'reason' => 'required|string|min:5|max:500',
        ], [
            'new_end_date.required' => 'New end date is required',
            'new_end_date.date_format' => 'New end date must be in format YYYY-MM-DD',
            'new_end_date.after' => 'New end date must be after the current deadline (' . $project->planned_end_date->format('M d, Y') . ')',
            'new_end_date.before_or_equal' => 'New end date cannot be beyond 2030',
            'reason.required' => 'Reason for extension is required',
            'reason.min' => 'Please provide a detailed reason (at least 5 characters)',
            'reason.max' => 'Reason limited to 500 characters',
        ]);
    }

    /**
     * Dispatch ExtensionStatusChanged notifications for a lifecycle event.
     *
     * Audiences:
     *  - SUBMITTED: all active admins (review required).
     *  - APPROVED/REJECTED: the requester (created_by_user_id) + the project's
     *    contractor (their deadline/request is affected). Rejection carries the
     *    rejection_reason so the requester can revise and resubmit.
     *
     * Dedupe key includes the status, so submit → approve / submit → reject /
     * resubmit → approve are all distinct events.
     */
    private function notifyExtension(Project $project, ProjectExtension $extension, string $status): void
    {
        $service = app(NotificationService::class);

        $notification = new ExtensionStatusChanged(
            $project->id,
            $project->title,
            $extension->id,
            $extension->extension_number,
            $status,
            $status === ExtensionStatusChanged::REJECTED ? $extension->rejection_reason : null
        );

        if ($status === ExtensionStatusChanged::SUBMITTED) {
            // Each submission/resubmission is a distinct review event — bypass
            // dedupe so a resubmitted request re-notifies the admins. (Only
            // scan-generated notifications rely on dedupe; SUBMITTED is
            // always event-driven.)
            $service->notifyMany($service->admins(), $notification, dedupe: false);
            return;
        }

        // Approved / rejected → requester + project contractor.
        $recipients = collect();

        if ($extension->created_by_user_id) {
            $requester = User::find($extension->created_by_user_id);
            if ($requester && $requester->is_active) {
                $recipients->push($requester);
            }
        }

        $contractorUser = $service->contractorUserFor($project);
        if ($contractorUser) {
            $recipients->push($contractorUser);
        }

        $service->notifyMany($recipients->unique('id'), $notification);
    }

    /**
     * Consistent JSON shape for an extension, now including status.
     */
    private function formatExtension(ProjectExtension $ext): array
    {
        return [
            'id' => $ext->id,
            'extension_number' => $ext->extension_number,
            'original_end_date' => $ext->original_end_date->format('Y-m-d'),
            'new_end_date' => $ext->new_end_date->format('Y-m-d'),
            'duration_days' => $ext->duration_days,
            'reason' => $ext->reason,
            'status' => $ext->status,
            'rejection_reason' => $ext->rejection_reason,
            'approved_by' => $ext->approver?->email ?? null,
            'approved_at' => $ext->approved_at?->format('Y-m-d H:i:s'),
        ];
    }
}
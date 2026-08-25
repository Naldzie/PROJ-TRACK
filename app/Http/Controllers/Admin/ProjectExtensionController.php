<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectActivityCarryover;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectExtension;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use App\Notifications\ExtensionStatusChanged;
use App\Services\NotificationService;
use App\Services\AI\ContractorPerformanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

/**
 * ProjectExtensionController
 *
 * Extension-management upgrade (extension_management_plan.md, branch
 * phase-extension-management). Supersedes the single-pending model with a
 * proposal queue, adds request-quality fields (reason category, evidence
 * attachments, review aging), baseline snapshot/restore on revert, partial
 * approval, resubmit-as-new-row, soft delete + archived panel, and fixes the
 * admin validation gap.
 */
class ProjectExtensionController extends Controller
{
    /**
     * Store a new project extension.
     *
     * ADMIN: created and auto-approved immediately — the project's
     *        planned_end_date/extension_count update right away, and any other
     *        pending proposals are re-anchored/superseded (Decision 2).
     * STAFF: created as a PENDING request only. Only ONE pending extension
     *        is allowed at a time — the user must wait for review before
     *        adding another. Admin may auto-approve or pick the status.
     */
    public function store(Request $request, Project $project)
    {
        try {
            $validated = $this->validateExtension($request, $project);

            $isAdmin = auth()->user()->role === 'admin';

            // Staff can only create extensions for assigned projects
            if (!$isAdmin && !$project->isAssignedTo(auth()->user())) {
                abort(403, 'You are not assigned to this project.');
            }

            // Admin and assigned staff may pick the review status at creation time
            // (defaults to approved, preserving the auto-approve behavior).
            if ($isAdmin || $project->isAssignedTo(auth()->user())) {
                $validated = array_merge($validated, $request->validate([
                    'status' => 'sometimes|nullable|in:pending,approved,rejected',
                    'rejection_reason' => 'required_if:status,rejected|nullable|string|min:5|max:500',
                ]));
            }

            $status = ($isAdmin || $project->isAssignedTo(auth()->user()))
                ? ($validated['status'] ?? 'approved')
                : 'pending';
            $rejectionReason = $status === 'rejected' ? ($validated['rejection_reason'] ?? null) : null;

            // Only ONE pending extension allowed at a time (consultant + client decision).
            if ($status === 'pending') {
                $hasPending = ProjectExtension::where('project_id', $project->id)
                    ->where('status', 'pending')
                    ->exists();
                if ($hasPending) {
                    return response()->json([
                        'success' => false,
                        'message' => 'There is already a pending extension request for this project. Please wait for it to be reviewed before adding another.',
                    ], 422);
                }
            }

            DB::beginTransaction();

            $maxExtensionNumber = DB::table('project_extensions')
                ->where('project_id', $project->id)
                ->max('extension_number') ?? 0;
            $nextExtensionNumber = $maxExtensionNumber + 1;

            $originalEndDate = $project->planned_end_date;
            $newEndDate = Carbon::parse($validated['new_end_date']);
            $durationDays = $originalEndDate->diffInDays($newEndDate);

            $canAutoApprove = ($isAdmin || $project->isAssignedTo(auth()->user())) && $status === 'approved';

            $extension = ProjectExtension::create([
                'project_id' => $project->id,
                'extension_number' => $nextExtensionNumber,
                'original_end_date' => $originalEndDate,
                'new_end_date' => $newEndDate,
                'duration_days' => $durationDays,
                'reason' => $validated['reason'],
                'reason_category' => $validated['reason_category'] ?? null,
                'status' => $status,
                'rejection_reason' => $rejectionReason,
                'approved_by_user_id' => $canAutoApprove ? auth()->id() : null,
                'approved_at' => $canAutoApprove ? now() : null,
                'created_by_user_id' => auth()->id(),
            ]);

            // Push the change to the project itself once the extension is
            // actually approved. For admin/staff this happens immediately when
            // the status is approved; for pending it happens later in
            // update()/approve.
            if ($canAutoApprove) {
                $this->resolvePendingQueue($project, $extension);
                $this->recomputeProjectDeadline($project);
            }

            DB::commit();

            // Audit the submission. Admin submissions are auto-approved by
            // default but may be created pending/rejected; staff submissions
            // sit pending until an admin approves them.
            $logAction = $isAdmin
                ? ($status === 'approved' ? 'Approve Extension'
                    : ($status === 'rejected' ? 'Reject Extension' : 'Request Extension'))
                : 'Request Extension';

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => $logAction,
                'description' => auth()->user()->name . ($isAdmin
                        ? " {$logAction} extension #{$nextExtensionNumber} for project: {$project->title}"
                        : " requested extension #{$nextExtensionNumber} ({$durationDays} days) for project: {$project->title}"),
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            // Notify: staff submissions go to admins; auto-approvals (admin or
            // assigned staff) go to the full recipient set including contractor.
            // Pending/rejected follow the same channels as submissions /
            // rejections respectively.
            $notifyStatus = $status === 'approved'
                ? ExtensionStatusChanged::APPROVED
                : ($status === 'rejected' ? ExtensionStatusChanged::REJECTED : ExtensionStatusChanged::SUBMITTED);

            $this->notifyExtension($project, $extension, $notifyStatus);

            $responseMessage = $isAdmin
                ? ($status === 'approved'
                    ? "Extension #{$nextExtensionNumber} created and approved"
                    : ($status === 'rejected'
                        ? "Extension #{$nextExtensionNumber} created and rejected"
                        : "Extension #{$nextExtensionNumber} created and pending approval"))
                : "Extension #{$nextExtensionNumber} submitted for admin approval";

            return response()->json([
                'success' => true,
                'message' => $responseMessage,
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
                ->with(['approver', 'attachments'])
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

            $extension->load(['approver', 'attachments']);

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
     * STAFF: may edit ONLY their OWN extension while it is 'pending' (still
     *        undecided — in-place edits are fine). Can change
     *        new_end_date/reason. Can NEVER set status directly — that field
     *        is stripped from their payload regardless of what's submitted.
     *        Rejected rows are IMMUTABLE for staff (Decision 14): the only
     *        way back to review is Resubmit, which creates a NEW row.
     *
     * ADMIN: may edit any extension's details AND status. Transitioning
     *        status -> 'approved' applies the deadline to the project and
     *        re-anchors/supersedes the pending queue (Decision 2).
     *        Transitioning the ACTIVE approved to pending/rejected reverts
     *        the project deadline AND restores the baseline snapshot
     *        (Phase 2.5). Non-active approved rows cannot be reverted
     *        (Decision 11). Admin new_end_date is capped at 2030 AND must be
     *        after the live deadline (Decision 8).
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
                $isAssignedStaff = $project->isAssignedTo($user);

                // Staff assigned to the project can edit ANY pending extension
                // (not just their own) — details only, NOT status. When the
                // request includes a 'status' field (approve/reject), skip
                // this edit path so the review path handles it.
                if ($isAssignedStaff && $extension->status === 'pending' && !$request->has('status')) {
                    $validated = $request->validate([
                        'new_end_date' => [
                            'sometimes', 'required', 'date_format:Y-m-d',
                            'after_or_equal:' . $project->planned_end_date->format('Y-m-d'),
                            'before_or_equal:2030-12-31',
                        ],
                        'reason' => 'sometimes|required|string|min:5|max:500',
                        'reason_category' => 'sometimes|nullable|string|in:' . implode(',', ProjectExtension::REASON_CATEGORIES),
                    ]);

                    DB::beginTransaction();

                    if (isset($validated['new_end_date'])) {
                        $newEndDate = Carbon::parse($validated['new_end_date']);
                        $extension->new_end_date = $newEndDate;
                        $extension->duration_days = $extension->original_end_date->diffInDays($newEndDate);
                    }
                    if (isset($validated['reason'])) {
                        $extension->reason = $validated['reason'];
                    }
                    if (array_key_exists('reason_category', $validated)) {
                        $extension->reason_category = $validated['reason_category'];
                    }

                    $extension->save();
                    DB::commit();

                    ActivityLog::create([
                        'user_id' => auth()->id(),
                        'project_id' => $project->id,
                        'action' => 'Update Extension',
                        'description' => auth()->user()->name
                            . " updated extension #{$extension->extension_number} for project: {$project->title}",
                        'ip_address' => request()->ip(),
                        'visibility' => 'all',
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Extension request updated',
                        'extension' => $this->formatExtension($extension),
                    ]);
                }

                // Staff assigned to the project can APPROVE/REJECT pending
                // extensions (review path — after external office decides).
                if ($isAssignedStaff && $extension->status === 'pending') {
                    $isRejecting = $request->input('status') === 'rejected';

                    $reviewRules = [
                        'reason' => 'sometimes|required|string|min:5|max:500',
                        'reason_category' => 'sometimes|nullable|string|in:' . implode(',', ProjectExtension::REASON_CATEGORIES),
                        'status' => 'required|in:approved,rejected',
                        'rejection_reason' => 'required_if:status,rejected|nullable|string|min:5|max:500',
                    ];

                    if ($isRejecting) {
                        $reviewRules['new_end_date'] = 'sometimes|nullable|date_format:Y-m-d';
                    } else {
                        $reviewRules['new_end_date'] = [
                            'sometimes', 'required', 'date_format:Y-m-d',
                            'after_or_equal:' . $project->planned_end_date->format('Y-m-d'),
                            'before_or_equal:2030-12-31',
                        ];
                    }

                    $validated = $request->validate($reviewRules);

                    DB::beginTransaction();

                    if (isset($validated['new_end_date'])) {
                        $newEndDate = Carbon::parse($validated['new_end_date']);
                        $extension->new_end_date = $newEndDate;
                        $extension->duration_days = $extension->original_end_date->diffInDays($newEndDate);
                    }
                    if (isset($validated['reason'])) {
                        $extension->reason = $validated['reason'];
                    }
                    if (array_key_exists('reason_category', $validated)) {
                        $extension->reason_category = $validated['reason_category'];
                    }

                    $extension->status = $validated['status'];
                    if ($validated['status'] === 'approved') {
                        $extension->approved_by_user_id = $user->id;
                        $extension->approved_at = now();
                        $extension->rejection_reason = null;
                    } else {
                        $extension->approved_by_user_id = null;
                        $extension->approved_at = null;
                        $extension->rejection_reason = $validated['rejection_reason'];
                    }

                    $extension->save();

                    $this->recomputeProjectDeadline($project);
                    $this->resolvePendingQueue($project, $extension);

                    DB::commit();

                    $logAction = $validated['status'] === 'approved' ? 'Approve Extension' : 'Reject Extension';
                    ActivityLog::create([
                        'user_id' => auth()->id(),
                        'project_id' => $project->id,
                        'action' => $logAction,
                        'description' => auth()->user()->name . " {$logAction} for project: {$project->title}",
                        'ip_address' => request()->ip(),
                        'visibility' => 'all',
                    ]);

                    $notifStatus = $validated['status'] === 'approved'
                        ? ExtensionStatusChanged::APPROVED
                        : ExtensionStatusChanged::REJECTED;
                    $this->notifyExtension($project, $extension, $notifStatus);

                    return response()->json([
                        'success' => true,
                        'message' => 'Extension ' . $validated['status'],
                        'extension' => $this->formatExtension($extension->load('attachments')),
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to modify this extension.',
                ], 403);
            }

            // --- Admin path ---
            $isRejecting = $request->input('status') === 'rejected';

            $baseRules = [
                'reason' => 'sometimes|required|string|min:5|max:500',
                'reason_category' => 'sometimes|nullable|string|in:' . implode(',', ProjectExtension::REASON_CATEGORIES),
                'status' => 'sometimes|required|in:pending,approved,rejected',
                'rejection_reason' => 'required_if:status,rejected|nullable|string|min:5|max:500',
            ];

            // new_end_date is required unless rejecting — date is irrelevant
            // for rejected extensions.
            if ($isRejecting) {
                $baseRules['new_end_date'] = 'sometimes|nullable|date_format:Y-m-d';
            } else {
                $baseRules['new_end_date'] = [
                    'sometimes', 'required', 'date_format:Y-m-d',
                    'after_or_equal:' . $project->planned_end_date->format('Y-m-d'),
                    'before_or_equal:2030-12-31',
                ];
            }

            $validated = $request->validate($baseRules);

            DB::beginTransaction();

            $wasApproved = $extension->isApproved();
            $transitioningToApproved = isset($validated['status'])
                && $validated['status'] === 'approved'
                && !$wasApproved;
            $isReverting = isset($validated['status'])
                && in_array($validated['status'], ['pending', 'rejected'], true)
                && $wasApproved;

            // Decision 11: only the ACTIVE (latest approved) extension can be
            // reverted. Reverting a non-active approved is blocked.
            if ($isReverting) {
                $activeApproved = $this->activeApproved($project);
                if (!$activeApproved || $activeApproved->id !== $extension->id) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Only the active approved extension can be reverted. Clean up older approved extensions by reverting them in reverse order.',
                    ], 403);
                }
            }

            if (isset($validated['new_end_date'])) {
                $newEndDate = Carbon::parse($validated['new_end_date']);
                $extension->new_end_date = $newEndDate;
                $extension->duration_days = $extension->original_end_date->diffInDays($newEndDate);
            }
            if (isset($validated['reason'])) {
                $extension->reason = $validated['reason'];
            }
            if (array_key_exists('reason_category', $validated)) {
                $extension->reason_category = $validated['reason_category'];
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

            // Baseline snapshot restore: reverting the ACTIVE approved rolls
            // the adjusted baseline back to the previous approved's state
            // (Phase 2.5 / §4). Runs inside the transaction so a blocked
            // restore (would lose recorded progress) aborts everything.
            if ($isReverting) {
                $this->restoreBaselineForRevert($project, $extension);
            }

            // The project's live deadline/extension_count always reflect the
            // latest APPROVED extension. Recomputing here — instead of only on
            // a ->approved transition — keeps the deadline correct for every
            // admin path: approving, reverting approved -> pending/rejected,
            // and editing an approved extension's new_end_date.
            $this->recomputeProjectDeadline($project);

            // After any approval, the newly-active approved deadline may
            // overtake other pending proposals (Decision 2).
            if ($transitioningToApproved || ($wasApproved && ($validated['status'] ?? null) === 'approved')) {
                $this->resolvePendingQueue($project, $extension);
            }

            DB::commit();

            $logAction = 'Update Extension';
            if ($transitioningToApproved) {
                $logAction = 'Approve Extension';
            } elseif ($isReverting) {
                $logAction = 'Revert Extension';
            } elseif (($validated['status'] ?? null) === 'rejected') {
                $logAction = 'Reject Extension';
            }

            // Phase 8 (Decision 16): visibility is tiered — operational actions
            // (Approve/Reject/Update) are shared ('all'); a revert is internal
            // decision detail (staff tier). The rejection reason is deliberately
            // kept OFF the shared description — the requester gets it via the
            // record + ExtensionStatusChanged notification; contractors see the
            // action but not the internal rationale.
            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => $logAction,
                'description' => auth()->user()->name . " {$logAction} for project: {$project->title}",
                'ip_address' => request()->ip(),
                'visibility' => $isReverting ? 'staff' : 'all',
            ]);

            // Notify on approve/reject: the requester (created_by_user_id) + the
            // project contractor. Rejection carries the reason for resubmission.
            if ($transitioningToApproved) {
                $this->notifyExtension($project, $extension, ExtensionStatusChanged::APPROVED);
            } elseif (($validated['status'] ?? null) === 'rejected') {
                $this->notifyExtension($project, $extension, ExtensionStatusChanged::REJECTED);
            }

            $revertMessage = null;
            if ($isReverting) {
                // §4.6: reverting moves the deadline only — it does NOT restore
                // the adjusted baseline by itself (the extension's snapshot may
                // be stale). Hint the admin to re-run Generate Adjusted Baseline.
                $revertMessage = 'Extension reverted. Re-approving later only moves the deadline — re-run "Generate Adjusted Baseline" to rebuild the plan.';
            }

            return response()->json([
                'success' => true,
                'message' => $revertMessage ?? 'Extension updated',
                'extension' => $this->formatExtension($extension->load('attachments')),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // A blocked baseline restore surfaces here (ValidationException
            // thrown by restoreBaselineForRevert with a 'baseline' error) —
            // return its message directly for a clean toast. Roll back the
            // open transaction (this can fire mid-transaction).
            DB::rollBack();
            $errors = $e->errors();
            $message = $errors['baseline'][0] ?? 'Validation failed';
            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $errors,
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
     * Resubmit a REJECTED extension as a brand-new row (Decision 6).
     *
     * Copies the rejected request's details into a NEW extension row (next
     * extension_number) in 'pending' status; the rejected row is NEVER mutated
     * and keeps its rejection_reason for the audit trail. Both staff and admin
     * may resubmit; the new row is always pending (even for admin — it goes
     * back through review so the rejection lesson stays visible). Staff may
     * only resubmit their OWN rejected rows (Decision 14).
     */
    public function resubmit(Request $request, Project $project, ProjectExtension $extension)
    {
        try {
            if ($extension->project_id !== $project->id) {
                return response()->json(['success' => false, 'message' => 'Extension not found'], 404);
            }

            if (!$extension->isRejected()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a rejected extension can be resubmitted.',
                ], 422);
            }

            $user = auth()->user();
            if ($user->role !== 'admin' && $extension->created_by_user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only resubmit your own rejected extension requests.',
                ], 403);
            }

            $validated = $this->validateExtension($request, $project);

            DB::beginTransaction();

            $maxExtensionNumber = DB::table('project_extensions')
                ->where('project_id', $project->id)
                ->max('extension_number') ?? 0;
            $nextExtensionNumber = $maxExtensionNumber + 1;

            $originalEndDate = $project->planned_end_date;
            $newEndDate = Carbon::parse($validated['new_end_date']);

            $newExtension = ProjectExtension::create([
                'project_id' => $project->id,
                'extension_number' => $nextExtensionNumber,
                'original_end_date' => $originalEndDate,
                'new_end_date' => $newEndDate,
                'duration_days' => $originalEndDate->diffInDays($newEndDate),
                'reason' => $validated['reason'],
                'reason_category' => $validated['reason_category'] ?? null,
                'status' => 'pending',
                'created_by_user_id' => auth()->id(),
            ]);

            DB::commit();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Resubmit Extension',
                'description' => auth()->user()->name . " resubmitted extension #{$extension->extension_number} as extension #{$nextExtensionNumber} for project: {$project->title}",
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);

            // The new pending row re-notifies the admins for review.
            $this->notifyExtension($project, $newExtension, ExtensionStatusChanged::SUBMITTED);

            return response()->json([
                'success' => true,
                'message' => "Extension #{$extension->extension_number} resubmitted as #{$nextExtensionNumber} for review",
                'extension' => $this->formatExtension($newExtension),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Extension resubmit error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error resubmitting extension: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an extension (SOFT delete — Decision 12).
     *
     * NO approved extension is directly deletable (Decision 10): it must first
     * be reverted (→ pending/rejected) then deleted. Staff may soft-delete
     * pending/rejected requests; admin may soft-delete any non-approved row.
     * Soft-deleted rows reappear in the admin Archived panel.
     */
    public function destroy(Request $request, Project $project, ProjectExtension $extension)
    {
        try {
            if ($extension->project_id !== $project->id) {
                return response()->json(['success' => false, 'message' => 'Extension not found'], 404);
            }

            $user = auth()->user();

            // Decision 10: approved extensions are never directly deletable —
            // regardless of role. Revert first, then delete.
            if ($extension->isApproved()) {
                if ($user->role !== 'admin') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Approved extensions can only be deleted by an administrator.',
                    ], 403);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete an approved extension directly. Revert it first, then delete.',
                ], 400);
            }

            // Decision 14: staff may only archive their OWN non-approved rows.
            if ($user->role !== 'admin' && $extension->created_by_user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only archive your own extension requests.',
                ], 403);
            }

            // Staff may only soft-delete pending/rejected/superseded requests.
            $extensionNumber = $extension->extension_number;
            $extension->delete();

            // Deleting a non-active approved extension may leave the project
            // deadline/extension_count stale, so recompute from whatever
            // approved extensions remain.
            $this->recomputeProjectDeadline($project);

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Delete Extension',
                'description' => auth()->user()->name . " deleted extension #{$extensionNumber} from project: {$project->title}",
                'ip_address' => request()->ip(),
                // Phase 8 (Decision 16): archiving is internal — staff + admin only.
                'visibility' => 'staff',
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
     * List soft-deleted (archived) extensions for a project. Admin only.
     * Decision 12 — Archived Extensions panel.
     */
    public function archived(Project $project)
    {
        try {
            if (auth()->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $extensions = $project->extensions()
                ->onlyTrashed()
                ->with(['approver', 'attachments'])
                ->orderBy('extension_number', 'asc')
                ->get()
                ->map(fn ($ext) => $this->formatExtension($ext));

            return response()->json([
                'success' => true,
                'extensions' => $extensions,
                'total' => $extensions->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Extension archived error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching archived extensions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted extension. Admin only. Decision 12.
     */
    public function restore(Request $request, Project $project, ProjectExtension $extension)
    {
        try {
            if (auth()->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            if ($extension->project_id !== $project->id) {
                return response()->json(['success' => false, 'message' => 'Extension not found'], 404);
            }

            $extensionNumber = $extension->extension_number;
            $extension->restore();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Restore Extension',
                'description' => auth()->user()->name . " restored extension #{$extensionNumber} for project: {$project->title}",
                'ip_address' => request()->ip(),
                // Phase 8 (Decision 16): admin housekeeping — admin only.
                'visibility' => 'admin_only',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Extension #{$extensionNumber} restored",
            ]);

        } catch (\Exception $e) {
            \Log::error('Extension restore error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error restoring extension: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Permanently delete a soft-deleted extension (hard delete). Admin only.
     * Decision 12 — Archived panel "Delete Forever" (also the dev/test-data
     * escape hatch). Removes the row's evidence attachments first so their
     * physical files are cleaned up.
     */
    public function forceDestroy(Request $request, Project $project, ProjectExtension $extension)
    {
        try {
            if (auth()->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            if ($extension->project_id !== $project->id) {
                return response()->json(['success' => false, 'message' => 'Extension not found'], 404);
            }

            $extensionNumber = $extension->extension_number;

            DB::transaction(function () use ($extension) {
                // Delete the record-level evidence (each Attachment model fires
                // its own deleting hook to remove the physical file).
                $extension->attachments->each->delete();
                $extension->forceDelete();
            });

            ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Delete Extension',
                'description' => auth()->user()->name . " permanently deleted extension #{$extensionNumber} from project: {$project->title}",
                'ip_address' => request()->ip(),
                // Phase 8 (Decision 16): admin housekeeping — admin only.
                'visibility' => 'admin_only',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Extension #{$extensionNumber} permanently deleted",
            ]);

        } catch (\Exception $e) {
            \Log::error('Extension forceDestroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error permanently deleting extension: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk archive (soft-delete) multiple extensions at once. Admin only.
     * §8 — bulk operations. Each row goes through the SAME guards as the
     * single-row destroy(): approved extensions are skipped (Decision 10 —
     * revert first), and any soft-deleted/stale rows are ignored. The project
     * deadline is recomputed once at the end.
     */
    public function bulkDestroy(Request $request, Project $project)
    {
        try {
            if (auth()->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $validated = $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer'],
            ]);

            $ids = array_values(array_filter($validated['ids']));
            $rows = $project->extensions()->whereIn('id', $ids)->get();

            $archived = [];
            $skipped = [];
            foreach ($rows as $extension) {
                // Decision 10: approved extensions are never directly
                // deletable — revert first, then delete.
                if ($extension->isApproved()) {
                    $skipped[] = $extension->extension_number;
                    continue;
                }
                $extension->delete();
                $archived[] = $extension->extension_number;
            }

            // Recompute from whatever approved extensions remain.
            $this->recomputeProjectDeadline($project);

            if (!empty($archived)) {
                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'project_id' => $project->id,
                    'action' => 'Delete Extension',
                    'description' => auth()->user()->name . " bulk-archived extension(s) #" . implode(', #', $archived) . " from project: {$project->title}",
                    'ip_address' => request()->ip(),
                    // Phase 8 (Decision 16): bulk ops are admin housekeeping.
                    'visibility' => 'admin_only',
                ]);
            }

            $message = 'Archived extension(s) #' . (implode(', #', $archived) ?: '—');
            if (!empty($skipped)) {
                $message .= '. Skipped approved extension(s) #' . implode(', #', $skipped) . ' — revert before deleting.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'archived' => count($archived),
                'skipped' => count($skipped),
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one extension to archive.',
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Extension bulkDestroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error archiving extensions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk permanently-delete (force-delete) multiple ARCHIVED extensions.
     * Admin only. §8 — bulk operations. Only soft-deleted rows of this project
     * are eligible; each row's evidence attachments are removed first so their
     * physical files are cleaned up (mirrors forceDestroy()).
     */
    public function bulkForceDestroy(Request $request, Project $project)
    {
        try {
            if (auth()->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $validated = $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer'],
            ]);

            $ids = array_values(array_filter($validated['ids']));
            $rows = $project->extensions()->onlyTrashed()->whereIn('id', $ids)->get();

            $deleted = [];
            DB::transaction(function () use ($rows, &$deleted) {
                foreach ($rows as $extension) {
                    // Record-level evidence first (Attachment deleting hook
                    // removes the physical file).
                    $extension->attachments->each->delete();
                    $extension->forceDelete();
                    $deleted[] = $extension->extension_number;
                }
            });

            if (!empty($deleted)) {
                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'project_id' => $project->id,
                    'action' => 'Delete Extension',
                    'description' => auth()->user()->name . " permanently deleted extension(s) #" . implode(', #', $deleted) . " from project: {$project->title}",
                    'ip_address' => request()->ip(),
                    // Phase 8 (Decision 16): bulk ops are admin housekeeping.
                    'visibility' => 'admin_only',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Permanently deleted extension(s) #' . (implode(', #', $deleted) ?: '—'),
                'deleted' => count($deleted),
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one extension to delete.',
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Extension bulkForceDestroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting extensions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk-restore soft-deleted extensions. Admin only.
     * Accepts { ids: [1, 2, 3] } — restores each and logs the action.
     */
    public function bulkRestore(Request $request, Project $project)
    {
        try {
            if (auth()->user()->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $validated = $request->validate([
                'ids' => ['required', 'array', 'min:1'],
                'ids.*' => ['required', 'integer'],
            ]);

            $ids = array_values(array_filter($validated['ids']));
            $rows = $project->extensions()->onlyTrashed()->whereIn('id', $ids)->get();

            $restored = [];
            foreach ($rows as $extension) {
                $extension->restore();
                $restored[] = $extension->extension_number;
            }

            if (!empty($restored)) {
                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'project_id' => $project->id,
                    'action' => 'Restore Extension',
                    'description' => auth()->user()->name . " restored extension(s) #" . implode(', #', $restored) . " for project: {$project->title}",
                    'ip_address' => request()->ip(),
                    'visibility' => 'admin_only',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Restored extension(s) #' . (implode(', #', $restored) ?: '—'),
                'restored' => count($restored),
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one extension to restore.',
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Extension bulkRestore error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error restoring extensions: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ===========================================================================
    // BASELINE SNAPSHOT + RESTORE (Phase 2.5 / §4)
    // ===========================================================================

    /**
     * Capture the current baseline schedule state onto the latest APPROVED
     * extension as a JSON restore point.
     *
     * Called from BaselineScheduleController::saveAdjustedBaseline() at the end
     * of a successful save. The snapshot is the WHOLE table state — a restore
     * point, not a live mirror. Reverting that approved extension restores
     * from the PREVIOUS approved's snapshot (see restoreBaselineForRevert).
     */
    public static function captureBaselineSnapshot(Project $project): void
    {
        $latestApproved = $project->extensions()
            ->where('status', 'approved')
            ->reorder('extension_number', 'desc')
            ->first();

        if (!$latestApproved) {
            return;
        }

        $months = ProjectBaselineSchedule::where('project_id', $project->id)
            ->orderBy('planned_date', 'asc')
            ->get();

        $snapshot = $months->map(function (ProjectBaselineSchedule $schedule) {
            return [
                'month_label' => $schedule->month_label,
                'planned_date' => $schedule->planned_date->format('Y-m-d'),
                'planned_progress_percent' => $schedule->planned_progress_percent,
                'original_planned_progress_percent' => $schedule->original_planned_progress_percent,
                'description' => $schedule->description,
                // Full-fidelity activities so restore recreates them exactly.
                'activities' => $schedule->activities()
                    ->get(['activity_name', 'description', 'is_completed'])
                    ->map(fn ($a) => [
                        'activity_name' => $a->activity_name,
                        'description' => $a->description,
                        'is_completed' => (bool) $a->is_completed,
                    ])
                    ->all(),
            ];
        })->values()->all();

        $latestApproved->update(['baseline_snapshot' => $snapshot]);
    }

    /**
     * Restore the baseline schedule to the state implied by reverting the
     * ACTIVE approved extension (§4.3/§4.4).
     *
     * Direction is critical: we restore from the NEW latest approved (i.e. the
     * PREVIOUS approved), NOT the reverted extension's own snapshot — the
     * reverted extension's snapshot is the state being reverted AWAY from.
     *
     *  - Previous approved HAS a snapshot → make the live table exactly match it.
     *  - Previous approved has NO snapshot, or no approved remains → restore
     *    the original plan (planned = original_planned_progress_percent for
     *    original rows; delete extension-only rows).
     *
     * Blocked (422) if the restore would delete a month with recorded progress
     * (§4.4). Throws ValidationException so the caller's catch rolls back.
     */
    private function restoreBaselineForRevert(Project $project, ProjectExtension $reverted): void
    {
        // The approved extension that becomes active after this revert.
        $previousApproved = $project->extensions()
            ->where('status', 'approved')
            ->where('id', '!=', $reverted->id)
            ->reorder('extension_number', 'desc')
            ->first();

        $snapshot = $previousApproved?->baseline_snapshot;

        if ($snapshot) {
            $this->restoreFromSnapshot($project, $snapshot);
        } else {
            $this->restoreOriginalPlan($project);
        }
    }

    /**
     * Make the live baseline table exactly match a stored snapshot.
     *  - Update matching rows (planned_progress_percent, planned_date,
     *    description, activities).
     *  - Recreate snapshot rows missing from the DB (they were deleted during a
     *    later adjustment — safe, since deletion is blocked for progress rows).
     *  - Delete current rows NOT in the snapshot (created for the reverted
     *    extension). Blocked if any such row has recorded progress.
     */
    private function restoreFromSnapshot(Project $project, array $snapshot): void
    {
        $currentRows = ProjectBaselineSchedule::where('project_id', $project->id)
            ->get()
            ->keyBy('month_label');

        $snapshotRows = collect($snapshot)->keyBy('month_label');

        // 1. Delete current rows NOT in the snapshot (months created for the
        //    reverted extension). Guard: no recorded progress (§4.4).
        $blockedMonths = [];
        foreach ($currentRows as $label => $row) {
            if ($snapshotRows->has($label)) {
                continue;
            }
            if ($row->progressHistory()->exists()) {
                $blockedMonths[] = $label;
            }
        }

        if (!empty($blockedMonths)) {
            throw ValidationException::withMessages([
                'baseline' => 'Cannot revert — the restore would remove month(s) with recorded progress: ' . implode(', ', $blockedMonths) . '. Archive or delete that progress first.',
            ]);
        }

        // 2. Delete current rows not in the snapshot.
        foreach ($currentRows as $label => $row) {
            if (!$snapshotRows->has($label)) {
                $this->deleteBaselineRow($project, $row);
            }
        }

        // 3. Update matching rows + recreate missing rows.
        foreach ($snapshotRows as $label => $snap) {
            $row = ProjectBaselineSchedule::where('project_id', $project->id)
                ->where('month_label', $label)
                ->first();

            if ($row) {
                $row->update([
                    'planned_date' => $snap['planned_date'],
                    'planned_progress_percent' => $snap['planned_progress_percent'],
                    'original_planned_progress_percent' => $snap['original_planned_progress_percent'],
                    'description' => $snap['description'] ?? null,
                ]);
                $this->syncActivities($project, $row, $snap['activities'] ?? []);
            } else {
                // Recreate a row deleted during a later adjustment.
                $newRow = ProjectBaselineSchedule::create([
                    'project_id' => $project->id,
                    'month_label' => $label,
                    'planned_date' => $snap['planned_date'],
                    'planned_progress_percent' => $snap['planned_progress_percent'],
                    'original_planned_progress_percent' => $snap['original_planned_progress_percent'],
                    'description' => $snap['description'] ?? null,
                ]);
                $this->syncActivities($project, $newRow, $snap['activities'] ?? []);
            }
        }
    }

    /**
     * Restore the ORIGINAL plan when the previous approved was never adjusted
     * (no snapshot) or no approved remains:
     *  - Rows that were part of the original plan (original_planned_progress_percent
     *    NOT NULL) get planned_progress_percent = original_planned_progress_percent.
     *  - Extension-only rows (original_planned_progress_percent IS NULL) are deleted.
     */
    private function restoreOriginalPlan(Project $project): void
    {
        $rows = ProjectBaselineSchedule::where('project_id', $project->id)->get();

        $blockedMonths = [];
        foreach ($rows as $row) {
            if ($row->original_planned_progress_percent === null) {
                // Extension-only row: would be deleted — guard on progress.
                if ($row->progressHistory()->exists()) {
                    $blockedMonths[] = $row->month_label;
                }
            }
        }

        if (!empty($blockedMonths)) {
            throw ValidationException::withMessages([
                'baseline' => 'Cannot revert — the restore would remove month(s) with recorded progress: ' . implode(', ', $blockedMonths) . '. Archive or delete that progress first.',
            ]);
        }

        foreach ($rows as $row) {
            if ($row->original_planned_progress_percent === null) {
                $this->deleteBaselineRow($project, $row);
            } else {
                $row->update([
                    'planned_progress_percent' => $row->original_planned_progress_percent,
                    // Restore the frozen original target date too — a manual
                    // Manage Month edit may have moved it within the month.
                    'planned_date' => $row->original_planned_date ?? $row->planned_date,
                    'description' => $row->description,
                ]);
            }
        }
    }

    /**
     * Delete a baseline row and its dependents in FK-safe order (mirrors
     * BaselineScheduleController::deleteBaselineSchedule).
     */
    private function deleteBaselineRow(Project $project, ProjectBaselineSchedule $schedule): void
    {
        ProjectProgressHistory::where('project_id', $project->id)
            ->where('baseline_schedule_id', $schedule->id)
            ->delete();
        ProjectActivity::where('project_id', $project->id)
            ->where('baseline_schedule_id', $schedule->id)
            ->delete();
        ProjectActivityCarryover::where('project_id', $project->id)
            ->where(function ($q) use ($schedule) {
                $q->where('from_baseline_schedule_id', $schedule->id)
                  ->orWhere('to_baseline_schedule_id', $schedule->id);
            })
            ->delete();
        $schedule->delete();
    }

    /**
     * Replace a baseline row's activities to match the snapshot (full-fidelity
     * restore).
     */
    private function syncActivities(Project $project, ProjectBaselineSchedule $schedule, array $activities): void
    {
        ProjectActivity::where('project_id', $project->id)
            ->where('baseline_schedule_id', $schedule->id)
            ->delete();

        foreach ($activities as $act) {
            if (empty($act['activity_name'])) {
                continue;
            }
            ProjectActivity::create([
                'project_id' => $project->id,
                'baseline_schedule_id' => $schedule->id,
                'month_label' => $schedule->month_label,
                'planned_date' => $schedule->planned_date,
                'activity_name' => $act['activity_name'],
                'description' => $act['description'] ?? '',
                'is_completed' => $act['is_completed'] ?? false,
            ]);
        }
    }

    /**
     * Decision 2: after an approval, re-anchor still-valid pending proposals to
     * the new live deadline and supersede the obsolete ones.
     *
     *  - Pending whose new_end_date is still AFTER the new live deadline →
     *    re-anchor original_end_date to the new deadline, recompute duration.
     *  - Pending whose new_end_date <= new live deadline → status becomes
     *    `superseded` (kept visible in history; not approvable; deletable).
     */
    private function resolvePendingQueue(Project $project, ProjectExtension $justApproved): void
    {
        $newDeadline = $justApproved->effectiveEndDate();

        $pendings = $project->extensions()
            ->where('status', 'pending')
            ->where('id', '!=', $justApproved->id)
            ->get();

        foreach ($pendings as $pending) {
            if ($pending->new_end_date->greaterThan($newDeadline)) {
                $pending->original_end_date = $newDeadline;
                $pending->duration_days = $pending->original_end_date->diffInDays($pending->new_end_date);
                $pending->save();
            } else {
                $pending->status = 'superseded';
                $pending->save();

                // Phase 8: log the queue cleanup so the supersede is traceable.
                // Staff tier — internal bookkeeping, not shown to contractors.
                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'project_id' => $project->id,
                    'action' => 'Supersede Extension',
                    'description' => auth()->user()->name
                        . " superseded extension #{$pending->extension_number} (overtaken by extension #{$justApproved->extension_number} approval) for project: {$project->title}",
                    'ip_address' => request()->ip(),
                    'visibility' => 'staff',
                ]);
            }
        }
    }

    /**
     * The latest approved extension (drives the project deadline).
     */
    private function activeApproved(Project $project): ?ProjectExtension
    {
        return $project->extensions()
            ->where('status', 'approved')
            ->reorder('extension_number', 'desc')
            ->first();
    }

    /**
     * Point the project's live deadline at the latest approved extension.
     *
     * Called after any admin status/date change or extension deletion so
     * planned_end_date and extension_count always reflect the current set of
     * approved extensions:
     *   - an approved extension -> planned_end_date = its effective date
     *     (approved_new_end_date when granted, else new_end_date — Decision 7),
     *   - no approved extensions -> falls back to the original deadline.
     *   - extension_count = count of APPROVED rows (Decision 6 — not just the
     *     highest approved number).
     */
    private function recomputeProjectDeadline(Project $project): void
    {
        $latestApproved = $this->activeApproved($project);
        $approvedCount = $project->extensions()
            ->where('status', 'approved')
            ->count();

        $earliestRemaining = $project->extensions()
            ->reorder('extension_number', 'asc')
            ->first();

        $project->update([
            'planned_end_date' => $latestApproved?->effectiveEndDate()
                ?? $project->original_planned_end_date
                ?? $earliestRemaining?->original_end_date
                ?? $project->planned_end_date,
            'extension_count' => $approvedCount,
        ]);

        if ($project->contractor) {
            app(ContractorPerformanceService::class)->recompute($project->contractor);
        }
    }

    /**
     * Validation logic for a brand-new extension request (also used by
     * resubmit). reason_category is optional but must be one of the defined
     * EOT grounds when provided.
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
            'reason_category' => 'nullable|string|in:' . implode(',', ProjectExtension::REASON_CATEGORIES),
        ], [
            'new_end_date.required' => 'New end date is required',
            'new_end_date.date_format' => 'New end date must be in format YYYY-MM-DD',
            'new_end_date.after' => 'New end date must be after the current deadline (' . $project->planned_end_date->format('M d, Y') . ')',
            'new_end_date.before_or_equal' => 'New end date cannot be beyond 2030',
            'reason.required' => 'Reason for extension is required',
            'reason.min' => 'Please provide a detailed reason (at least 5 characters)',
            'reason.max' => 'Reason limited to 500 characters',
            'reason_category.in' => 'Reason category must be one of: ' . implode(', ', ProjectExtension::REASON_CATEGORIES),
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
        $triggerUserId = auth()->id();

        $currentEnd = \Carbon\Carbon::parse($extension->original_end_date);
        $effectiveEnd = \Carbon\Carbon::parse($extension->effectiveEndDate());
        $daysExtended = $currentEnd->diffInDays($effectiveEnd);

        $notification = new ExtensionStatusChanged(
            $project->id,
            $project->title,
            $extension->id,
            $extension->extension_number,
            $status,
            $status === ExtensionStatusChanged::REJECTED ? $extension->rejection_reason : null,
            $status === ExtensionStatusChanged::APPROVED
                ? $effectiveEnd->format('M d, Y')
                : null,
            $currentEnd->format('M d, Y'),
            $daysExtended
        );

        $recipients = collect();

        // Always include assigned staff (except the trigger user).
        $assignedStaff = $service->staffForProject($project);
        if ($assignedStaff && $assignedStaff->id !== $triggerUserId) {
            $recipients->push($assignedStaff);
        }

        if ($status === ExtensionStatusChanged::SUBMITTED) {
            // Submission → all admins (except trigger) + assigned staff.
            // Dedupe bypassed so resubmissions re-notify.
            foreach ($service->admins() as $admin) {
                if ($admin->id !== $triggerUserId) {
                    $recipients->push($admin);
                }
            }
            $service->notifyMany($recipients->unique('id'), $notification, dedupe: false);
            return;
        }

        // Approved / Rejected → requester + contractor + assigned staff + admins,
        // minus the trigger user so nobody receives their own notification.

        if ($extension->created_by_user_id && $extension->created_by_user_id !== $triggerUserId) {
            $requester = User::find($extension->created_by_user_id);
            if ($requester && $requester->is_active) {
                $recipients->push($requester);
            }
        }

        $contractorUser = $service->contractorUserFor($project);
        if ($contractorUser && $contractorUser->id !== $triggerUserId) {
            $recipients->push($contractorUser);
        }

        foreach ($service->admins() as $admin) {
            if ($admin->id !== $triggerUserId) {
                $recipients->push($admin);
            }
        }

        $service->notifyMany($recipients->unique('id'), $notification);
    }

    /**
     * Consistent JSON shape for an extension, including status, partial-approval
     * date, reason category, review-aging, and evidence attachments.
     */
    private function formatExtension(ProjectExtension $ext): array
    {
        $attachments = $ext->relationLoaded('attachments')
            ? $ext->attachments
            : $ext->attachments()->get();

        return [
            'id' => $ext->id,
            'extension_number' => $ext->extension_number,
            'original_end_date' => $ext->original_end_date->format('Y-m-d'),
            'new_end_date' => $ext->new_end_date->format('Y-m-d'),
            'effective_end_date' => $ext->effectiveEndDate()->format('Y-m-d'),
            'duration_days' => $ext->duration_days,
            'reason' => $ext->reason,
            'reason_category' => $ext->reason_category,
            'status' => $ext->status,
            'rejection_reason' => $ext->rejection_reason,
            'approved_by' => $ext->approver?->email ?? null,
            // Decision 15: human-friendly approver name for the table.
            'approved_by_name' => $ext->approver?->name ?? null,
            'approved_at' => $ext->approved_at?->format('Y-m-d H:i:s'),
            // Review aging (Decision 5): days awaiting review for pendings.
            'days_awaiting_review' => $ext->isPending() ? $ext->daysAwaitingReview() : 0,
            // Decision 14/15: requester id (for ownership checks) + name.
            'created_by_user_id' => $ext->created_by_user_id,
            'created_by' => $ext->requester?->name ?? null,
            'created_at' => $ext->created_at?->format('Y-m-d H:i:s'),
            // Evidence (Decision 4).
            'attachments_count' => $attachments->count(),
            'attachments' => $attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'file_url' => $a->file_url,
                'display_size' => $a->display_size,
                'uploaded_by' => $a->uploaded_by,
                'created_at' => $a->created_at->format('M d, Y H:i'),
            ])->values()->all(),
        ];
    }
}

# STAFF BASELINE & EXTENSION IMPROVEMENTS PLAN

CSPC PMU System — client-confirmed improvements for staff baseline editing,
extension approval, additional work %, auto-assignment, and project filtering.

Date:     August 22, 2026
Branch:   staff-baseline-extension-improvements (from develop)

---

## Background / Client Feedback

- Staff assigned to a project should be able to edit the original (frozen)
  baseline of their responsible projects. Currently admin-only.
- Staff should be able to approve (or auto-approve) extensions for projects
  they are assigned to.
- Extensions mimic the original baseline + adjustment based on additional work.
  Add a field in the "Generate Adjusted Baseline" modal for the approximated
  additional % worth of the extension.
- When a staff member creates a project, they should be auto-assigned as
  the project manager. Staff cannot reassign — only admin can.
- Staff should only see their assigned projects in the projects index.
- Only assigned staff and admin should be able to edit projects, manage
  baselines, and manage extensions (defense-in-depth, not just UI hiding).
- Risks module stays as-is (client doesn't use it but it's already in place).
- S-curve baseline is based on contractor-sent S-curve plans (cumulative %
  per month, format varies by contractor).

---

## Task 9 — Staff Can Edit Original Baseline + Detailed Logging

Goal: Allow staff to edit the frozen original baseline of projects they
are assigned to (via assigned_project_manager). Add detailed before/after
diff logging so admin can see who edited, what the original data was, and
what was changed — enabling identification of mistakes.

### Current Authorization Chain (4 layers, all admin-only)

| Layer | File | Line(s) | Current Check |
|-------|------|---------|---------------|
| Route | routes/web.php | 534, 536 | Routes only in admin group (role:admin middleware) |
| Controller GET | BaselineScheduleController.php | 1089 | role !== 'admin' → 403 |
| Controller POST | BaselineScheduleController.php | 1156 | role !== 'admin' → 403 |
| Blade | baseline-schedule.blade.php | 81 | @if(auth()->user()->role === 'admin') |
| JS | baseline-schedule-setup-modal.js | 284 | None (relies on window.routePrefix) |

Note: `assigned_project_manager` stores User IDs (fixed in this branch).
The old format stored Admin/Staff record IDs which overlapped (both had
IDs 1, 2, 3), causing wrong PM names to display. A migration converts
existing values to User IDs.

### Changes

- routes/web.php
  - Add GET and POST /original routes to the staff route group (lines
    ~156-175) so staff can hit the same controller endpoints:
    ```
    Route::get('/projects/{project}/baseline-schedules/original',
        [BaselineScheduleController::class, 'getOriginalBaselineMonths'])
        ->name('staff.projects.baseline-schedules.original');
    Route::post('/projects/{project}/baseline-schedules/original/save',
        [BaselineScheduleController::class, 'saveEditedOriginalBaseline'])
        ->name('staff.projects.baseline-schedules.original.save');
    ```
  - The JS uses window.routePrefix ('staff.projects') to build the
    endpoint URL, so the staff routes must exist for the fetch to work.

- app/Models/Project.php
  - Add helper method:
    ```
    public function isAssignedTo(User $user): bool
    {
        if ($user->role !== 'staff' || !$user->staff) return false;
        return (string) $this->assigned_project_manager === (string) $user->staff->id;
    }
    ```

- app/Http/Controllers/BaselineScheduleController.php
  - Add private helper:
    ```
    private function canEditOriginalBaseline(User $user, Project $project): bool
    {
        if ($user->role === 'admin') return true;
        return $project->isAssignedTo($user);
    }
    ```
  - In getOriginalBaselineMonths(): replace `role !== 'admin'` check
    with `!$this->canEditOriginalBaseline(auth()->user(), $project)`.
  - In saveEditedOriginalBaseline(): same replacement.
  - In saveEditedOriginalBaseline(): BEFORE the upsert loop, fetch all
    current original months and build a before-snapshot array:
    ```
    $beforeMonths = $project->baselineSchedules()
        ->whereNotNull('original_planned_progress_percent')
        ->orderBy('planned_date')
        ->get(['month_label', 'planned_date', 'planned_progress_percent',
               'original_planned_progress_percent', 'description'])
        ->toArray();
    ```
  - AFTER the save + validation succeeds, build an after-snapshot and
    compute the diff. Log with structured data:
    ```
    ActivityLog::create([
        'user_id'    => auth()->id(),
        'project_id' => $project->id,
        'action'     => 'Edit Original Baseline',
        'description'=> auth()->user()->name . ' edited the original baseline',
        'ip_address' => request()->ip(),
        'visibility'=> 'all',
        'metadata'   => json_encode([
            'before' => $beforeMonths,
            'after'  => $afterMonths,
            'removed'=> $validated['removed_ids'] ?? [],
        ]),
    ]);
    ```
  - This lets admin see: who made the change, what the old months were,
    what the new months are, and which months were removed — enabling
    identification of mistakes.

- app/Models/ActivityLog.php
  - Add 'metadata' to $fillable and $casts (as 'array').
  - If the activity_logs table doesn't have a metadata column, add a
    migration:
    ```
    $table->json('metadata')->nullable()->after('visibility');
    ```

- resources/views/projects/project_detail/project_detail.blade.php
  - Compute $isAssignedStaff = $project->isAssignedTo(auth()->user())
  - Pass $isAssignedStaff to the baseline-schedule tab include.

- resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php
  - Change the Edit Original Baseline button condition from:
    @if(auth()->user()->role === 'admin')
  - To:
    @if(auth()->user()->role === 'admin' || $isAssignedStaff)

### Implementation Log — Task 9

Committed: `eb10a56` + follow-up fixes on `staff-baseline-extension-improvements`

Files changed:
- `routes/web.php` — Added GET/POST `/original` routes to staff group
- `app/Models/Project.php` — Added `isAssignedTo(User $user)` helper
- `app/Models/ActivityLog.php` — Added `metadata` to $fillable + $casts
- `database/migrations/2026_08_22_093055_add_metadata_column_to_activity_logs_table.php` — New migration
- `app/Http/Controllers/BaselineScheduleController.php`:
  - Added `canEditOriginalBaseline()` private helper
  - Replaced admin-only checks in both methods
  - Before/after snapshots use `->map()` to store clean scalar dates (Y-m-d)
  - Added before/after diff logging with metadata on ActivityLog
- `resources/views/projects/project_detail/project_detail.blade.php` — Computes $isAssignedStaff
- `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php` — Button shows for assigned staff
- `resources/views/admin/login.blade.php`:
  - Added 'Edit Original Baseline' to action filter dropdown
  - Pure Blade server-side diff tooltip (no JS dependency)
  - CSS fix: `color: #e2e8f0` on `.diff-tooltip .diff-table td` to prevent dark-on-dark text
  - Month labels formatted as "Month Name - YYYY" (e.g. "January - 2027")
  - Added distinct purple badge (`action-edit-baseline`) for this action

All 725 tests passing.

---

## Task 10 — Staff Can Approve Extensions for Assigned Projects

Goal: Staff can approve or auto-approve extensions for projects they are
assigned to. Admin approval still works for all projects.

Current state:
- store(): staff submissions always set to 'pending' (line ~68).
- update(): staff cannot change status (line ~318 strips status).
- Review modal is admin-only (blade + JS).
- canReview in baseline-schedule-extensions.js only checks isAdmin.

Changes:
- app/Http/Controllers/Admin/ProjectExtensionController.php
  - In store(): when staff creates extension for a project where
    assigned_project_manager matches their Staff ID, set status to
    'approved' (auto-approve) instead of 'pending'. Also immediately
    recompute deadline + extension_count (same as admin auto-approve).
  - In update(): allow staff to change status (approve/reject) when the
    project is assigned to them. Remove the line that strips status from
    staff payload when isAssignedStaff is true.

- resources/views/projects/project_detail/components/modals/review_extension_modal.blade.php
  - Show the Review modal for staff assigned to the project (not just
    admin). Change the @if check to include $isAssignedStaff.

- public/js/admin/projects/baseline-schedule-extensions.js
  - Update canReview: add `|| (isAssignedStaff && ext.status === 'pending')`.
  - Pass isAssignedStaff from a window variable set in the blade.

- resources/views/projects/project_detail/components/tabs/partials/extensions-section.blade.php
  - Set window.isAssignedStaff = {{ $isAssignedStaff ? 'true' : 'false' }}
    for the JS to read.

---

## Task 11 — Additional Work % in Adjusted Baseline Modal

Goal: Add an "Additional Work %" input in the "Generate Adjusted Baseline"
modal. This represents the estimated additional scope from the extension.
The auto-distribution algorithm uses this to scale the cumulative curve
(not just stretch time).

Current state:
- No additional_work_percent field exists.
- generateAutoDistribution() only stretches the curve across new time.
- The adjust-baseline mode reuses _setup_baseline_modal.blade.php.

Changes:
- database/migrations/xxxx_add_additional_work_percent_to_project_extensions.php
  New migration:
  $table->decimal('additional_work_percent', 5, 2)->nullable()->default(0);

- app/Models/ProjectExtension.php
  - Add 'additional_work_percent' to $fillable.

- resources/views/projects/project_detail/components/modals/_setup_baseline_modal.blade.php
  - In the modal header area, add an input field visible only in
    adjust-baseline mode (when setupAdjustMode === true):
    Label: "Approximated Additional Work %"
    Input: type=number, min=0, max=100, step=0.01, default 0
    Help text: "Estimated additional scope from the extension period."

- public/js/admin/projects/baseline-schedule-setup-modal.js
  - In openAdjustBaselineModal(): show the additional work % input,
    pre-fill from the latest extension's additional_work_percent value.
  - In generateAutoDistribution(): accept additionalWorkPercent param.
    After stretching the curve, scale all adjustable months' planned
    progress_percent by (1 + additionalWorkPercent/100). The final month
    must still reach 100% of the original + additional scope.
  - In onSetupBaselineDone() (adjust mode): include the additional work
    percent in the POST payload.

- app/Http/Controllers/BaselineScheduleController.php
  - In saveAdjustedBaseline(): accept additional_work_percent, store it
    on the latest approved extension record.
  - In getBaselineMonthsForAdjustment(): return the stored additional
    work percent so the modal can pre-fill.

- public/js/admin/projects/s-curve-chart.js
  - When additional work % > 0, the adjusted plan line may extend beyond
    100%. Update y_max to accommodate. Update Y-axis label to show
    "Cumulative Progress (%)" with a note if additional scope is present.

---

## Task 12 — Auto-Assign Staff as Project Manager on Creation

Goal: When a staff member creates a project, automatically set
assigned_project_manager to their User ID. Staff cannot reassign the
project manager — only admins can.

Current state:
- store() has no auto-assign logic.
- assigned_project_manager is manually selected from dropdown.
- Any staff member can edit any project's PM field.

Changes:
- app/Http/Controllers/ProjectController.php
  - In store(): after validation, if auth user is staff and
    assigned_project_manager is null/empty, set it to the user's ID:
    ```
    if ($user->role === 'staff' && empty($validated['assigned_project_manager'])) {
        $validated['assigned_project_manager'] = (string) $user->id;
    }
    ```
  - In update(): strip assigned_project_manager from staff payload:
    ```
    if (auth()->user()->role === 'staff') {
        unset($projectData['assigned_project_manager']);
    }
    ```

- resources/views/admin/_create_project_modal.blade.php
  - When the creating user is staff, disable the PM dropdown and show
    only their name. A hidden input carries their User ID for form
    submission.
  - When admin, full dropdown with all admins/staff (unchanged).

- public/js/_create_project_modal.js
  - In openEditProjectModal(): if dropdown is disabled (staff), update
    the hidden input with the current PM value instead of trying to
    set the disabled select.

### Implementation Log — Task 12

Committed: `cd8c4b5`, `f20b2eb`, `afc46f0` on `staff-baseline-extension-improvements`

Files changed:
- `app/Http/Controllers/ProjectController.php`:
  - `store()`: Auto-assigns user ID as PM when staff creates without selecting
  - `update()`: Strips `assigned_project_manager` for staff — only admin can reassign
- `resources/views/admin/_create_project_modal.blade.php`:
  - PM dropdown disabled for staff with hidden input carrying their User ID
  - Pre-selects staff member's own option (now replaced by disabled state)
- `public/js/_create_project_modal.js`:
  - Edit modal sets hidden input value when dropdown is disabled (staff)

Note: `assigned_project_manager` stores User IDs (fixed earlier in this branch).
Auto-assign uses `$user->id` directly.

All 725 tests passing.

---

## Task 13 — Staff Project Filtering

Goal: Staff should only see their assigned projects in the projects index.
Projects created by staff are auto-assigned (Task 12). Admin-added projects
must be manually assigned to staff.

Current state:
- Staff see ALL projects (identical to admin, line ~112 comment).

Changes:
- app/Http/Controllers/ProjectController.php
  - In index(): when role is staff, add filter:
    $query->where('assigned_project_manager', (string) $user->id)
    This replaces the current "Admin and Staff see all projects" behavior.
  - Ensure that projects where assigned_project_manager is null (admin
    created but not yet assigned) are NOT shown to staff.

- resources/views/projects/index.blade.php
  - For staff, the "New Project" button should still be visible (staff
    can create projects which are auto-assigned to them).

Note: This task is deferred to LAST so that during testing, staff accounts
can still see and access all projects in the index. The access-restriction
gates (Task 14) will be tested first while projects remain visible.

---

## Task 14 — Restrict Project Operations to Assigned Staff + Admin

Goal: Defense-in-depth access control. Even if a staff member navigates
to an unassigned project (via URL, bookmark, or shared link), they cannot
edit the project, modify its baseline, manage activities, record progress,
or manage its extensions. Only admin and the assigned project manager can
perform these operations.

Current state (audit results):

| Feature | Admin | Any Staff | Assigned Staff |
|---|---|---|---|
| Edit project (header button) | Yes | Yes (any) | No gate |
| Set Up Baseline | Yes | Yes (any) | No gate |
| Add Month Target | Yes | Yes (any) | No gate |
| Generate Adjusted Baseline | Yes | Yes (any) | No gate |
| Delete month target | Yes | Partial | No gate |
| Edit Original Baseline | Yes | No | **Already correct** |
| Add/Edit/Delete Activity | Yes | Yes (any) | No gate |
| Toggle Activity Completion | Yes | Yes (any) | No gate |
| Carry Over Activity | Yes | Yes (any) | No gate |
| Record Progress | Yes | Yes (any) | No gate |
| Edit Progress | Yes | Latest only | No gate |
| Add Extension | Yes | Yes (any project) | No gate |
| Edit/Delete Extension | Yes | Own only | **Already correct** |

### Changes

#### A. Edit Project Button (header.blade.php)

- resources/views/projects/project_detail/components/header.blade.php
  - The edit button uses `@can('update', $project)`. Currently
    ProjectPolicy@update allows ANY staff. Change to:
    ```
    public function update(User $user, Project $project): bool
    {
        if ($user->role === 'admin') return true;
        return $project->isAssignedTo($user);
    }
    ```
  - This restricts the edit button AND the controller update route
    to admin + assigned staff only.

#### B. Baseline CRUD + Activities Restriction

- app/Http/Controllers/BaselineScheduleController.php
  - Add a new private helper (generalizing canEditOriginalBaseline):
    ```
    private function authorizeProjectAccess(User $user, Project $project): void
    {
        if ($user->role === 'admin') return;
        if (!$project->isAssignedTo($user)) {
            abort(403, 'You are not assigned to this project.');
        }
    }
    ```
  - Add `$this->authorizeProjectAccess(auth()->user(), $project)` at the
    top of these methods:
    - `storeBaselineSchedule()`
    - `storeInitialBaselineSetup()`
    - `updateBaselineSchedule()`
    - `deleteBaselineSchedule()`
    - `carryOverActivity()`
    - `saveAdjustedBaseline()`
  - Refactor `canEditOriginalBaseline()` to call `authorizeProjectAccess()`
    internally (or replace it) for consistency.

- app/Http/Controllers/ProjectActivityController.php
  - Add `$this->authorizeProjectAccess(auth()->user(), $project)` at the
    top of these methods:
    - `storeActivity()`
    - `deleteActivity()`
    - `toggleActivityCompletion()` — load project from activity first

- resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php
  - Wrap "Set Up Baseline", "Add Month Target", "Generate Adjusted Baseline"
    buttons with `@if(auth()->user()->role === 'admin' || $isAssignedStaff)`
    to hide them for non-assigned staff.
  - Per-card "Delete" and "Manage Month" buttons: add `$isAssignedStaff`
    check alongside the existing admin check.
  - Activity checkboxes: add `disabled` attribute for non-assigned staff,
    or wrap with `$isAssignedStaff` condition.
  - "Record Progress" and "Edit Progress" buttons: add `$isAssignedStaff`
    check alongside the existing admin check.
  - "Carry Over" button: add `$isAssignedStaff` check.

#### C. Progress Recording Restriction

- app/Http/Controllers/ProjectProgressController.php
  - Add `$this->authorizeProjectAccess(auth()->user(), $project)` at the
    top of these methods:
    - `recordMonthlyProgress()`
    - `editProgressRecord()`
    - `destroyProgress()`
    - `storeProgress()` (legacy, but still protected)

- The `authorizeProjectAccess()` helper is shared from BaselineScheduleController.
  If ProjectProgressController needs it, either extract to a trait or call
  via `$this->app->make(BaselineScheduleController::class)` — or simpler:
  duplicate the 5-line helper in ProjectProgressController as well.

#### D. Extension CRUD Restriction

- app/Http/Controllers/Admin/ProjectExtensionController.php
  - In `store()`: add assignment check before creating:
    ```
    if (!$isAdmin && !$project->isAssignedTo(auth()->user())) {
        abort(403, 'You are not assigned to this project.');
    }
    ```
  - Staff extensions for own projects remain as-is (auto-approve for
    assigned staff, pending for non-assigned — but non-assigned will
    now be blocked at the gate).

- resources/views/projects/project_detail/components/tabs/partials/extensions-section.blade.php
  - Change "Add Extension" button condition from:
    `auth()->user()->role !== 'contractor'`
  - To:
    `auth()->user()->role === 'admin' || $isAssignedStaff`

- public/js/admin/projects/baseline-schedule-extensions.js
  - Update `canEdit`/`canDelete`/`canResubmit` to also check
    `isAssignedStaff` (pass via window variable from blade).

### Implementation Log — Task 14

( pending )

---

## Implementation Order

1. Task 9 (staff baseline edit) — DONE
2. Task 12 (auto-assign + PM read-only) — DONE
3. Task 14 (restrict project operations) — NEXT
4. Task 13 (staff filtering) — LAST (deferred for testing convenience)
5. Task 10 (staff extension approval) — after Task 14
6. Task 11 (additional work %) — independent, anytime

---

## Verification

- Run composer test after each task
- Manual QA:
  * Staff creates project → auto-assigned to them
  * Staff cannot change PM in create/edit modal (dropdown disabled)
  * Admin can assign PM to any project
  * Staff CANNOT edit project details of unassigned projects (edit button hidden, 403 on direct URL)
  * Staff CAN edit project details of assigned projects
  * Staff CANNOT set up / add month / generate adjusted baseline for unassigned projects
  * Staff CAN do all baseline operations for assigned projects
  * Staff CANNOT add/edit/delete activities on unassigned projects
  * Staff CAN add/edit/delete activities on assigned projects
  * Staff CANNOT record/edit/delete progress on unassigned projects
  * Staff CAN record/edit/delete progress on assigned projects
  * Staff CANNOT add extension for unassigned projects
  * Staff CAN add/edit/delete extensions for assigned projects (own extensions only for edit/delete)
  * Admin can do all operations for all projects
  * Staff sees only assigned projects in the index (Task 13 — deferred)
  * Generate Adjusted Baseline modal shows Additional Work % input (Task 11)
  * S-curve updates correctly when additional work % is entered (Task 11)
  * ActivityLog captures before/after diffs for baseline edits

---

## Out of Scope

- Risk module changes (kept as-is per client confirmation)
- Contractor self-service (client confirmed PMU personnel input S-curves)
- Legacy name-string assigned_project_manager records (test data only,
  can be removed; new projects store User IDs as strings)
- Uploaded-by / edited-by fields currently store display names (cosmetic,
  not a collision risk since names aren't numeric IDs)

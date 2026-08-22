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
  the project manager.
- Staff should only see their assigned projects in the projects index.
- Risks module stays as-is (client doesn't use it but it's already in place).
- S-curve baseline is based on contractor-sent S-curve plans (cumulative %
  per month, format varies by contractor).

---

## Task 9 — Staff Can Edit Original Baseline + Detailed Logging

Goal: Allow staff to edit the frozen original baseline of projects they
are assigned to (via assigned_project_manager). Add detailed before/after
diff logging for baseline edits.

Current state:
- BaselineScheduleController::getOriginalBaselineMonths() returns 403 if
  role !== 'admin' (line ~1089).
- saveEditedOriginalBaseline() same admin-only check (line ~1156).
- Blade button hidden behind @if(auth()->user()->role === 'admin').
- ActivityLog records action but no before/after field values.

Files:
- app/Http/Controllers/BaselineScheduleController.php
  - In getOriginalBaselineMonths(): replace `role !== 'admin'` check
    with: user is admin OR (user is staff AND
    project.assigned_project_manager matches user's Staff record ID).
  - In saveEditedOriginalBaseline(): same authorization check.
  - In saveEditedOriginalBaseline(): before upserting, capture the old
    values of each month being edited. After save, log an ActivityLog
    entry with structured before/after JSON (not just a description
    string). Format:
    ```
    action: 'Edit Original Baseline'
    description: 'Admin/Staff X edited the original baseline for Project Y'
    extra: { before: [...old months...], after: [...new months...] }
    ```
  - The ActivityLog model already has a `project_id` column. We can store
    the diff in the `description` field as JSON or add a new `metadata`
    JSON column if needed.

- resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php
  - Compute $isAssignedStaff: staff user whose Staff ID matches
    $project->assigned_project_manager.
  - Change @if(auth()->user()->role === 'admin') on the Edit Original
    Baseline button to @if(auth()->user()->role === 'admin' || $isAssignedStaff).

- resources/views/projects/project_detail/project_detail.blade.php
  - Pass $isAssignedStaff to the baseline-schedule tab include.

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
assigned_project_manager to their Staff record ID.

Current state:
- store() has no auto-assign logic.
- assigned_project_manager is manually selected from dropdown.

Changes:
- app/Http/Controllers/ProjectController.php
  - In store(): after validation, if auth user is staff and
    assigned_project_manager is null/empty, set it to the staff user's
    Staff record ID:
    ```
    if ($user->role === 'staff' && empty($validated['assigned_project_manager'])) {
        $validated['assigned_project_manager'] = $user->staff?->id;
    }
    ```

- resources/views/admin/_create_project_modal.blade.php
  - When the creating user is staff, pre-select their value in the
    assigned_project_manager dropdown (optional UX improvement).

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
    $query->where('assigned_project_manager', $user->staff?->id)
    This replaces the current "Admin and Staff see all projects" behavior.
  - Ensure that projects where assigned_project_manager is null (admin
    created but not yet assigned) are NOT shown to staff.

- resources/views/projects/index.blade.php
  - For staff, the "New Project" button should still be visible (staff
    can create projects which are auto-assigned to them).

---

## Implementation Order

Recommended order based on dependencies:

1. Task 12 (auto-assign) — prerequisite for Task 13 and Task 9/10
2. Task 13 (staff filtering) — depends on Task 12
3. Task 9 (staff baseline edit) — depends on Task 12 for assignment check
4. Task 10 (staff extension approval) — depends on Task 12
5. Task 11 (additional work %) — independent, can be done anytime

---

## Verification

- Run composer test after each task
- Manual QA:
  * Staff creates project → auto-assigned to them
  * Staff sees only their assigned projects
  * Staff can edit original baseline of assigned projects only
  * Staff can approve extensions for assigned projects
  * Generate Adjusted Baseline modal shows Additional Work % input
  * S-curve updates correctly when additional work % is entered
  * ActivityLog captures before/after diffs for baseline edits

---

## Out of Scope

- Risk module changes (kept as-is per client confirmation)
- Contractor self-service (client confirmed PMU personnel input S-curves)
- Migration to convert assigned_project_manager from string to FK
  (kept for backward compatibility with existing data)

# CONSULTANT ADJUSTMENTS PLAN

CSPC PMU System — minor UI/UX adjustments recommended by the consultant and
confirmed by the client.

Date:     August 21, 2026
Branch:   consultant-adjustments (from develop)

---

## Task 1 — Dashboard Status Card Reorder + Completed Budget Tooltip

Goal: Reorder the 5 stat cards so Completed sits next to Total Projects.
Show total completed project budget on hover.

Current order: Total, Ongoing, Overdue, At Risk, Completed
New order:     Total, Completed, Ongoing, Overdue, At Risk

Files:
- app/Http/Controllers/DashboardController.php
  - After completedCount (line ~47), add a sum query:
    `$completedBudget = Project::where('status', 'Completed')->sum('approved_budget');`
  - Add $completedBudget to the compact() array (line ~109).

- resources/views/admin/dashboard.blade.php (lines 139-203)
  - Move the Completed card block (lines 194-201) to right after Total
    Projects (after line 149).
  - On the Completed card, add a title attribute on .db-stat-sub showing
    the formatted budget:
    `title="Total Budget: {FormatHelper::formatCurrency($completedBudget)}"`
  - No CSS changes needed — the existing 5-column grid auto-reorders.

---

## Task 2 — Extension Button Label to "Add Extension"

Goal: Change "Request Extension" to "Add Extension" for both admin and staff.

Files:
- resources/views/projects/project_detail/components/tabs/partials/extensions-section.blade.php
  - Line 18: "Request Extension" -> "Add Extension"

- resources/views/projects/project_detail/components/modals/request_extension_modal.blade.php
  - Line 209: default text -> "Add Extension"
  - Line 285: remove the JS role-based label toggle, both roles
    see "Add Extension"

- public/js/admin/projects/baseline-schedule-extensions.js
  - Line 223: empty state text "Request Extension" -> "Add Extension"

---

## Task 3 — Remove Partial Approval from Review Modal

Goal: Simplify the review modal to only "Approve as Requested" and "Reject".
Remove the "Approve with Less Time" option from the REVIEW modal only.
The admin can still set an approved date via the request/edit modal
(partialApprovalWrapper stays in request_extension_modal.blade.php).

Files:
- resources/views/projects/project_detail/components/modals/review_extension_modal.blade.php
  - Remove the "Approve with Less Time" button (line ~60).
  - Remove the reviewGrantedDate / reviewGrantedDays inputs and
    syncReviewPartialApproval() JS function.
  - Remove the partial decision branch from the submit handler.
  - Keep only "Approve as Requested" and "Reject" buttons.
  - On submit: approved extensions always use new_end_date as the
    effective date (no approved_new_end_date override from this modal).

- app/Http/Controllers/Admin/ProjectExtensionController.php
  - store() (lines 82-95): Keep partial approval logic for admin-created
    extensions (they can still set approved_new_end_date via edit modal).
  - update() (lines 386-401): Keep partial approval sanity check for
    admin edit flow. The review modal just no longer sends partial data.

Note: The partialApprovalWrapper in request_extension_modal.blade.php is
KEPT as-is. Admins can still set a granted date when creating/editing
extensions directly. Only the dedicated review modal loses the 3-button
choice.

---

## Task 4 — Only 1 Extension Request at a Time

Goal: Block creating a new extension if there is already a pending one.
Confirmed by client and consultant.

Files:
- app/Http/Controllers/Admin/ProjectExtensionController.php
  - In store(), after validateExtension() and before DB::beginTransaction,
    add a pending check:
    ```
    $hasPending = ProjectExtension::where('project_id', $project->id)
        ->where('status', 'pending')
        ->exists();
    if ($hasPending) {
        return response()->json([
            'success' => false,
            'message' => 'There is already a pending extension request for this project. Please wait for it to be reviewed before adding another.',
        ], 422);
    }
    ```

- resources/views/projects/project_detail/components/tabs/partials/extensions-section.blade.php
  - (Optional) Hide the "Add Extension" button when a pending extension
    exists. Requires passing $hasPendingExtension from controller or
    checking via JS after extensions table loads.

---

## Task 5 — Email + In-App Notification for Approved Extensions (to Contractor)

Goal: When an extension is approved, send email + in-app notification to the
project's contractor with the approved date and a disclaimer that the
extension may still be changed.

Files:
- app/Notifications/ExtensionStatusChanged.php
  - Add new constructor parameter: `?string $approvedEndDate = null`
  - In toMail() for APPROVED status:
    - Update intro: "Your project extension request has been approved."
    - Add line: "New Deadline: {approvedEndDate formatted}"
    - Add disclaimer: "Please note that this extension may still be
      subject to change depending on project circumstances."
  - In toDatabase() / toArray(): include approved_end_date in data
    payload for click-to-navigate (Task 6).

- app/Http/Controllers/Admin/ProjectExtensionController.php
  - In notifyExtension() (lines 1334-1370): pass the effectiveEndDate()
    to the notification constructor for APPROVED status.
  - Verify contractor is already notified (currently done at lines
    1362-1369 via NotificationService::contractorUserFor()).

---

## Task 6 — Clickable In-App Notifications + Unread Dots on Projects

Goal: Make notifications clickable to navigate to the correct project page
and tab. Add unread notification dots to project cards on the projects
index page (like unread message dots in messaging apps).

### Part A — Click-to-Navigate

Files:
- app/Notifications/ExtensionStatusChanged.php
  - Add public function url(): return route for project detail + #extensions

- app/Notifications/IssueReported.php
  - Add public function url(): return route for project detail + #issues

- app/Notifications/RiskReported.php
  - Add public function url(): return route for project detail + #risks

- app/Notifications/DeadlineReminder.php
  - Add public function url(): return route for project detail + #overview

- app/Notifications/OverdueInspectionReminder.php
  - Add public function url(): return route for project detail
    + #baseline-schedule

- resources/views/notifications/partials/bell.blade.php
  - Store the notification URL ($n->url()) in a data-navigate-url
    attribute on each .notification-item.
  - On click: POST to mark as read, then
    window.location.href = item.dataset.navigateUrl
    (instead of location.reload()).

- resources/views/notifications/_list.blade.php
  - Make notification rows clickable with the same navigate behavior.
  - Add a small "View" link or icon that navigates to the project.

### Part B — Unread Dots on Projects Index

Files:
- app/Http/Controllers/ProjectController.php (or relevant controller)
  - Query unread notification counts per project:
    ```
    $unreadByProject = Auth::user()->unreadNotifications()
        ->get()
        ->pluck('data.project_id')
        ->filter()
        ->countBy();
    ```
  - Pass $unreadByProject to the view.

- resources/views/projects/index.blade.php
  - For each project card, if
    $unreadByProject->get($project->id, 0) > 0,
    render a small red dot badge (absolute-positioned on card corner).

---

## Verification

- Run composer test after each task (725 tests must pass)
- Manual QA:
  * Dashboard: cards in new order, hover shows budget
  * Extensions: "Add Extension" label, 1-at-a-time block, review modal
    has only Approve/Reject, approved extension sends email to contractor
  * Notifications: clicking navigates to correct project+tab, unread dots
    appear on project cards

---

## Out of Scope

- PPT/PPTX preview (kept as download-only)
- Staff approval/rejection of extensions (consultant wants this but
  deferred to a future iteration)
- Bulk extension operations (unchanged)
- Migration to remove approved_new_end_date column (kept for data integrity)

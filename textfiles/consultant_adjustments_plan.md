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

## Task 3 — Remove Partial Approval

Goal: Simplify both the review modal and the edit modal — remove the
"Approve with Less Time" / partial approval option entirely.

Files:
- resources/views/projects/project_detail/components/modals/review_extension_modal.blade.php
  - Removed the "Approve with Less Time" button.
  - Removed reviewGrantedDate / reviewGrantedDays inputs and
    syncReviewPartialApproval() JS function.
  - Removed _prefillReviewPartial() function.
  - Simplified setReviewDecision() to 2-button (Approve/Reject).
  - Removed partial branch from submitReviewExtension().
  - Updated docblock.

- resources/views/projects/project_detail/components/modals/request_extension_modal.blade.php
  - Removed partialApprovalWrapper div (Granted End Date field).
  - Removed approvedNewEndDate references from toggleRejectionReasonField(),
    openRequestExtensionModal(), openEditExtensionModal(), and submitExtension().
  - Removed partial approval submit logic and client-side validation for
    granted date.
  - Removed partial approval min-date setter in DOMContentLoaded.

- app/Http/Controllers/Admin/ProjectExtensionController.php
  - store() and update(): kept approved_new_end_date handling for backward
    compatibility, but no UI sends it anymore.

---

## Task 4 — Only 1 Extension Request at a Time

Goal: Block creating a new extension if there is already a pending one.
Confirmed by client and consultant.

Files:
- app/Http/Controllers/Admin/ProjectExtensionController.php
  - store(): added pending check before DB::beginTransaction — returns
    422 if a pending extension already exists for the project.

- resources/views/projects/project_detail/components/tabs/partials/extensions-section.blade.php
  - Added id="addExtensionBtn" to the Add Extension button.

- public/js/admin/projects/baseline-schedule-extensions.js
  - In reloadExtensionsTable AJAX success handler, toggles button
    visibility: hidden when any extension has status === 'pending',
    visible otherwise.

- tests/Feature/ProjectExtensionLifecycleTest.php
  - Updated test_staff_cannot_submit_second_pending_extension to expect
    422 JSON response instead of 302 redirect.

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

## Task 7 — Restrict Reject and Archive to Eligible Extensions

Goal: Only the LATEST extension can be rejected (Review button).
Only pending or rejected extensions can be archived (Archive button).
Hide the Archive button for extensions that cannot be archived.

Current state:
- canReview: any pending extension → admin sees Review button on all pending
- canDelete (admin): any except active approved → admin can archive approved
- canDelete (staff): own pending/rejected → already correct

Changes:
- public/js/admin/projects/baseline-schedule-extensions.js
  - Compute latestExtensionId = extensions[0].id (first in list = highest
    extension_number, since list is sorted desc).
  - canReview: add `&& ext.id === latestExtensionId` — only the latest
    extension shows the Review button.
  - canDelete (admin): change from `!isActiveApproved` to
    `ext.status === 'pending' || ext.status === 'rejected'` — approved
    extensions cannot be archived.
  - canDelete (staff): unchanged (already pending/rejected only).

Files:
- public/js/admin/projects/baseline-schedule-extensions.js

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

---

## Implementation Log

Track commits and changes as tasks are completed.

### Task 1 — Dashboard reorder (committed: f0ebe99)
- Reordered stat cards: Total → Completed → Ongoing → Overdue → At Risk
- Added $completedBudget query and title tooltip on Completed card
- Files: DashboardController.php, admin/dashboard.blade.php

### Task 2 — Extension label (committed: 625114e)
- Changed "Request Extension" → "Add Extension" for all roles
- Files: extensions-section.blade.php, request_extension_modal.blade.php,
  baseline-schedule-extensions.js

### Task 3 — Remove partial approval (committed: 415405c)
- Removed "Approve with Less Time" from review modal (2-button only)
- Removed partialApprovalWrapper, approvedNewEndDate JS, and partial
  approval submit logic from edit modal
- Files: review_extension_modal.blade.php, request_extension_modal.blade.php

### Task 4 — 1 extension at a time (committed: 9b36bb0)
- Server-side: 422 if pending extension exists (store())
- Client-side: button hidden when pending extensions exist
- Updated test to expect 422
- Files: ProjectExtensionController.php, extensions-section.blade.php,
  baseline-schedule-extensions.js, ProjectExtensionLifecycleTest.php

### Rejection fix (committed: 2a163f2)
- Server-side: skip new_end_date validation when rejecting (update())
- Client-side: skip HTML5 checkValidity() and custom date check when
  status is 'rejected' (submitExtension)
- Files: ProjectExtensionController.php, request_extension_modal.blade.php

### Task 7 — Restrict reject and archive (pending)
- Only latest extension can be rejected (Review button)
- Only pending/rejected can be archived (Archive button)
- File: baseline-schedule-extensions.js

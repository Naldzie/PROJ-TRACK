# Baseline Adjustments & Progress Guards — Change Summary

Branch: `feature/baseline-date-bounds`
Date: 2026-08-10

This document records all adjustments on this branch, from the baseline
timeline-bounds work through the frozen-progress-months / edit-guard changes.
Four commits are covered:

1. `5f24ae3` — Enforce baseline target dates within project timeline
2. `bbf9f18` — Show existing activities in adjust-baseline modal
3. `3f16027` — Preserve activity identity in adjust-baseline save
4. `d2f761c` — Freeze progressed months and restrict non-admin progress edits

---

## 1. Enforce baseline target dates within project timeline (`5f24ae3`)

**Problem:** Month targets could be created/edited outside the project's planned
timeline (before the planned start date or after the planned end date), which
breaks the rule that "the baseline IS the plan."

**Server changes:**

- `app/Http/Controllers/BaselineScheduleController.php`
  - `storeBaselineSchedule`: rejects (422) a target date before the project's
    planned start date or after the project's planned end date.
  - `storeInitialBaselineSetup`: validates every submitted month target against
    the same project timeline bounds before locking a baseline.
- `app/Http/Controllers/ProjectController.php`
  - `store` (create project): validates `baseline_schedule_targets` against the
    entered planned start/end dates **before** the project is created, so
    nothing is written when the plan is out of bounds. Also bounds the legacy
    `baseline_schedule_month` (YYYY-MM) path to the timeline.

**Frontend changes:**

- `public/js/admin/projects/baseline-schedule-add-month-modal.js`
  - Date pickers in Add/Edit month-target modals are bounded with `min`/`max`
    from the `#s-curve-planning` `data-project-start-date`/`data-project-end-date`.
  - `saveMonthTarget` / `saveMonthTargetEdit` add client-side validation for the
    same bounds.
- `public/js/admin/projects/baseline-schedule-setup-modal.js`
  - New `getSetupDateBounds()` helper resolves bounds from
    `__setupModalContext` (create-project flow) or `#s-curve-planning` (project
    detail flow).
  - `addSetupBaselineMonth`, `editSetupBaselineMonth`, and `resetSetupForm`
    apply/validate the timeline bounds.
- `public/js/_create_project_modal.js`
  - Passes `startDate` / `endDate` into `__setupModalContext`.
- `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`
  - Exposes `data-project-start-date` / `data-project-end-date` on
    `#s-curve-planning`.

**Tests:** `tests/Feature/BaselineScheduleDateBoundsTest.php` (new, 367 lines),
`ProjectExtensionLifecycleTest.php` (+17 lines).

## 2. Show existing activities in adjust-baseline modal (`bbf9f18`)

**Problem:** The adjust-baseline modal initialized every month with an empty
activity list, so existing activities appeared to be deleted whenever the user
opened and saved the modal.

**Changes:**

- `app/Http/Controllers/BaselineScheduleController.php`
  - `getBaselineMonthsForAdjustment`: includes each month's existing activities
    (`id`, `activity_name`, `description`) in the response payload.
- `public/js/admin/projects/baseline-schedule-setup-modal.js`
  - `openAdjustBaselineModal` populates the modal from the server-returned
    activities instead of `[]`.

**Tests:** `tests/Feature/BaselineScheduleDateBoundsTest.php` (+68 lines) —
adjust endpoint returns existing activities.

## 3. Preserve activity identity in adjust-baseline save (`3f16027`)

**Problem:** `saveAdjustedBaseline` deleted and re-created every activity in an
edited month, which:
- churned IDs (broke carry-over log references),
- reset `is_completed` on already-completed activities,
- orphaned carry-over history.

**Changes:**

- `app/Http/Controllers/BaselineScheduleController.php`
  - `saveAdjustedBaseline` now does a **diff-based upsert** instead of
    delete+recreate: matched rows (by round-tripped `id`, or name fallback) are
    updated in place; only rows the user actually removed are deleted. New rows
    are created. Validation accepts `months.*.activities.*.id`.
- `public/js/admin/projects/baseline-schedule-setup-modal.js`
  - Activities carry their original `id` through the modal (`openAdjustBaselineModal`,
    `onSetupBaselineDone`, `addSetupBaselineMonth`), and hidden `setup-activity-id`
    inputs are rendered on edit/add rows.

**Tests:** `tests/Feature/BaselineScheduleAdjustActivitiesTest.php` (new, 238
lines) — identity preservation, `is_completed` never reset, carry-over logs keep
pointing at the same row, removed activities deleted, new activities created.

## 4. Freeze progressed months and restrict non-admin progress edits (`d2f761c`)

**Context:** Once a month has recorded progress, its actuals feed the S-curve
trend, AI regression, and variance analysis. Previously any role could rewrite
historical actuals and re-scope progressed months, which could silently distort
trend data and orphan activity references in progress history.

### 4a. Hide "Manage Month" on progressed months (all roles)

**File:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`

- The "Manage Month" button is only rendered when the month has **no** progress
  record (`@if(!$latestProgress)`). Un-progressed months keep full functionality
  (target editing + activity management pre-inspection).

### 4b. Server guard: no activity add/delete on progressed months (uniform block)

**File:** `app/Http/Controllers/ProjectActivityController.php`

- `storeActivity`: rejects with 422 when the month already has any
  `ProjectProgressHistory` record.
- `deleteActivity`: rejects with 422 when the activity's `month_label` has any
  progress record. This also closes the orphaned-`activity_id` hazard — deleting
  an activity referenced by a progress record's JSON array previously broke
  name resolution in the progress tab.
- No admin override (uniform block). Carry Over remains the sanctioned path to
  move pending activities out of a progressed month.

### 4c. Hide "Delete Month Target" for staff (progressed + original-baseline months)

**File:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`

- The Delete button is only rendered when:
  - the user is **admin**, OR
  - the month is **extension-only** (`original_planned_progress_percent === null`)
    AND has **no** progress.
- Contractor already has no baseline delete route — unchanged.

**File:** `app/Http/Controllers/BaselineScheduleController.php`

- `deleteBaselineSchedule`: added an authoritative non-admin guard (checked
  before any DB transaction / deletion) that rejects with 403 when:
  - the month is part of the **original baseline**
    (`original_planned_progress_percent` NOT NULL), or
  - the month already has **recorded progress**.
- Admin retains full delete access.

### 4d. Non-admin can edit only the newest progress record (admin edits all)

**File:** `app/Http/Controllers/ProjectProgressController.php`

- `editProgressRecord`: rejects with 403 when a non-admin edits anything except
  the **latest** progress record (same ordering as the delete guard:
  `month_label DESC, inspection_date DESC, id DESC`). Admin passes the guard.

**File:** `resources/views/projects/project_detail/components/tabs/progress.blade.php`

- The Edit button renders only on the newest row (`$loop->first`) for non-admin
  users; admin sees it on every row.

**File:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`

- The "Edit Progress" button on a month card only renders for non-admin when
  that month is the **latest progress month**
  (`$plan->month_label === $latestProgressMonth`); admin always sees it.

### 4e. Tests (new)

| Test class | Coverage |
|---|---|
| `tests/Feature/Services/AI/ProgressEditGuardTest.php` | Staff cannot edit a non-latest record (403); staff can edit the latest; admin can edit any. |
| `tests/Feature/Services/AI/ProgressedMonthActivityGuardTest.php` | Admin and staff cannot add/delete activities on a progressed month (422); activities still addable on un-progressed months. |
| `tests/Feature/Services/AI/BaselineDeleteGuardTest.php` | Staff cannot delete original-baseline months (403) or progressed months (403); staff can delete extension-only, un-progressed months; admin can delete any. |

---

## Verification

- `composer test`: **526 passed (1893 assertions)** covering all four commits'
  changes.
- Pint (`vendor/bin/pint --dirty`) was run on the changed PHP files.

## Behavior matrix (staff / non-admin) — after commit 4

| Action | Original-baseline month | Extension month, no progress | Extension month, has progress |
|---|---|---|---|
| Manage Month | Hidden (if progressed) / visible pre-progress | Visible | Hidden |
| Add/Delete activities | Blocked if progressed | Allowed | Blocked |
| Delete Month Target | Blocked (403) | Allowed | Blocked (403) |
| Edit Progress (card) | Only if latest month | n/a | Only if latest month |
| Edit Progress (history tab) | Only newest row | n/a | Only newest row |

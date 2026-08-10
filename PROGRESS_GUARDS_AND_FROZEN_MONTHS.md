# Progress Guards & Frozen Months — Change Summary

Branch: `feature/baseline-date-bounds`
Date: 2026-08-10

## Context

Once a month has recorded progress, its actuals feed the S-curve trend, AI
regression, and variance analysis. Prior to this change, any role could rewrite
historical actuals and re-scope progressed months, which could silently distort
trend data and orphan activity references in progress history.

This change freezes progressed months and restricts historical edits for
non-admin (staff) users while preserving admin override for corrections.

## Changes

### 1. Hide "Manage Month" on progressed months (all roles)

**File:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`

- The "Manage Month" button is now only rendered when the month has **no**
  progress record (`@if(!$latestProgress)`).
- Months without progress keep full functionality (target editing + activity
  management pre-inspection).

### 2. Server guard: no activity add/delete on progressed months (uniform block)

**File:** `app/Http/Controllers/ProjectActivityController.php`

- `storeActivity`: rejects with 422 when the month already has any
  `ProjectProgressHistory` record.
- `deleteActivity`: rejects with 422 when the activity's `month_label` has any
  progress record. This also closes the orphaned-`activity_id` hazard — deleting
  an activity referenced by a progress record's JSON array previously broke
  name resolution in the progress tab.
- No admin override (uniform block). Carry Over remains the sanctioned path to
  move pending activities out of a progressed month.

### 3. Hide "Delete Month Target" for staff (progressed + original-baseline months)

**File:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`

- The Delete button is now only rendered when:
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

### 4. Non-admin can edit only the newest progress record (admin edits all)

**File:** `app/Http/Controllers/ProjectProgressController.php`

- `editProgressRecord`: added a guard that rejects with 403 when a non-admin
  user edits anything except the **latest** progress record (same ordering as
  the delete guard: `month_label DESC, inspection_date DESC, id DESC`).
- Admin passes the guard and can edit any record.

**File:** `resources/views/projects/project_detail/components/tabs/progress.blade.php`

- The Edit button now renders only on the newest row (`$loop->first`) for
  non-admin users; admin sees it on every row.

**File:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`

- The "Edit Progress" button on a month card only renders for non-admin when
  that month is the **latest progress month**
  (`$plan->month_label === $latestProgressMonth`); admin always sees it.

## Tests

Three new test classes were added under `tests/Feature/Services/AI/` (mirroring
the existing `ProgressDeleteGuardTest` conventions):

| Test class | Coverage |
|---|---|
| `ProgressEditGuardTest` | Staff cannot edit a non-latest record (403); staff can edit the latest; admin can edit any. |
| `ProgressedMonthActivityGuardTest` | Admin and staff cannot add/delete activities on a progressed month (422); activities still addable on un-progressed months. |
| `BaselineDeleteGuardTest` | Staff cannot delete original-baseline months (403) or progressed months (403); staff can delete extension-only, un-progressed months; admin can delete any. |

## Verification

- `composer test`: **526 passed (1893 assertions)**
- Pint (`vendor/bin/pint --dirty`) was run on the changed PHP files.

## Behavior matrix (staff / non-admin)

| Action | Original-baseline month | Extension month, no progress | Extension month, has progress |
|---|---|---|---|
| Manage Month | Hidden (if progressed) / visible pre-progress | Visible | Hidden |
| Add/Delete activities | Blocked if progressed | Allowed | Blocked |
| Delete Month Target | Blocked (403) | Allowed | Blocked (403) |
| Edit Progress (card) | Only if latest month | n/a | Only if latest month |
| Edit Progress (history tab) | Only newest row | n/a | Only newest row |

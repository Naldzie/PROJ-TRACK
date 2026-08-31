# Bugs & Behavior Adjustments — Part 2

**Continuation of:** `textfiles/bugs_and_behavior_adjustments.md`
**Date:** September 1, 2026
**Status:** E12 planned, not yet implemented.

---

## Enhancement 12: Planned End Date Restriction — Prevent End Date Before Start Date

**Date:** September 1, 2026
**Branch:** `fix/project-date-restriction`
**Status: PLANNED** — Not yet implemented.

### Problem

The create project modal allows users to pick a planned end date that is earlier than the planned start date. The backend already validates `planned_end_date` must be `after_or_equal:planned_start_date`, but the frontend has no restriction — users can freely select invalid dates, then receive a confusing server-side validation error.

### Current State

| Layer | Restriction | Status |
|---|---|---|
| Frontend (create modal) | **None** — `<input type="date">` with no min/max | Missing |
| Backend `store()` | `after_or_equal:planned_start_date` on `planned_end_date` | Exists (line 611) |
| Backend `update()` | `after_or_equal:planned_start_date` on `planned_end_date` | Exists (line 1001) |
| Backend symmetric rule | **None** — `planned_start_date` has no `before_or_equal:planned_end_date` | Missing |

### Fix

#### Frontend — `public/js/_create_project_modal.js`

Add min/max constraints on start/end date inputs using the same pattern as `request_extension_modal.blade.php:679-691` (native `<input type="date">` with JS-set `.min`/`.max` attributes — browser natively grays out invalid dates).

**After the existing date calculation handlers (~line 886):**

1. On `plannedStartDate` change → set `plannedEndDate.min` to the selected start date
2. On `plannedEndDate` change → set `plannedStartDate.max` to the selected end date
3. On `plannedEndDate` change → also set `projectHandoverDate.min` to enforce handover after end date

#### Backend — `app/Http/Controllers/ProjectController.php`

Add symmetric validation rule:

| Method | Field | Current Rule | New Rule |
|---|---|---|---|
| `store()` (line 609) | `planned_start_date` | `['nullable', 'date']` | `['nullable', 'date', 'before_or_equal:planned_end_date']` |
| `update()` (line 999) | `planned_start_date` | `['nullable', 'date']` | `['nullable', 'date', 'before_or_equal:planned_end_date']` |

### Files to Modify

| File | Change | ~Lines |
|---|---|---|
| `public/js/_create_project_modal.js` | Add min/max constraint handlers for start/end date | ~15 |
| `app/Http/Controllers/ProjectController.php` | Add `before_or_equal:planned_end_date` on `planned_start_date` in `store()` and `update()` | 2 |

### Effort

Low — ~17 lines total across 2 files.

---

## Session Log: September 1, 2026

### Changes Planned

| Item | Change | Files | Status |
|---|---|---|---|
| E12 | Frontend date constraints + backend symmetric rule | `_create_project_modal.js`, `ProjectController.php` | PLANNED |

### Implementation Order

1. **E12** (date restriction on create project modal) — PLANNED

---

**Continued in:** Next session or `bugs_and_behavior_adjustments_part3.md` if needed.

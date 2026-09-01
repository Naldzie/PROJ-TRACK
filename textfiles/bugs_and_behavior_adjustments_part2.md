# Bugs & Behavior Adjustments — Part 2

**Continuation of:** `textfiles/bugs_and_behavior_adjustments.md`
**Date:** September 1, 2026
**Status:** E12 IMPLEMENTED, E13 IMPLEMENTED.

---

## Enhancement 12: Planned End Date Restriction — Prevent End Date Before Start Date

**Date:** September 1, 2026
**Branch:** `fix/project-date-restriction`
**Status: IMPLEMENTED** — Frontend date constraints + backend symmetric rule added.

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
| E12 | Frontend date constraints + backend symmetric rule | `_create_project_modal.js`, `ProjectController.php` | IMPLEMENTED |

### Implementation Details

**Frontend** (`public/js/_create_project_modal.js`):
- Added `plannedStartDate` change handler → sets `plannedEndDate.min` (gray out dates before start)
- Added `plannedEndDate` change handler → sets `plannedStartDate.max` (gray out dates after end) + `projectHandoverDate.min` (enforce handover after end)

**Backend** (`app/Http/Controllers/ProjectController.php`):
- `store()` line 609: `planned_start_date` rule changed from `['nullable', 'date']` to `['nullable', 'date', 'before_or_equal:planned_end_date']`
- `update()` line 999: same change

### Implementation Order

1. ~~**E12** (date restriction on create project modal)~~ — IMPLEMENTED
2. **E13** (baseline setup modal month/date restrictions + save-time warnings) — PLANNED

---

## Enhancement 13: Baseline Setup Modal — Month/Date Restrictions & Save-Time Warnings

**Date:** September 1, 2026
**Branch:** `fix/baseline-setup-date-restrictions`
**Status: IMPLEMENTED** — Dynamic month/date constraints + save-time warning modal. **Updated:** First-month min logic corrected per user feedback.

### Problem

The baseline schedule setup modal lets users add monthly targets with a month picker (`<input type="month">`) and target date (`<input type="date">`). Currently there are no restrictions on which months/dates can be picked beyond the project timeline bounds. Users can:

1. Pick a first monthly target with a month/date earlier than the project start or current date
2. Pick non-sequential months (e.g. skip from March to July)
3. Add monthly targets past the project's planned end date
4. Save a baseline where the first/last month doesn't match the project start/end month (with no warning)

### Requirements

| # | Rule | Behavior |
|---|------|----------|
| 1 | First target: no earlier than `max(today, planned_start_date)` | Month picker min = `max(current month, start month)`. Date picker min = `max(today, planned_start_date)` |
| 2 | Subsequent targets: only the next month after the latest recorded | Month picker locked to exactly one month (min=max=next month). Date picker constrained to that month's range |
| 3 | Cannot add targets past planned end date | If the next month exceeds the planned end date's month, disable "Add Month" button |
| 4 | Warning on save if first/last month doesn't match project start/end month | Non-blocking Bootstrap warning modal. User can confirm to proceed or cancel |

### Fix

#### 1. New Reusable Component — `resources/views/components/warning-confirm-modal.blade.php`

**New file** — follows the same pattern as `delete-confirmation-modal.blade.php` but with amber/orange theme:

- `id="warningConfirmModal"`
- Yellow/amber gradient header with `bi-exclamation-triangle-fill` icon
- Title + message (raw HTML supported)
- Cancel (gray) + Confirm (amber) buttons
- Global JS: `showWarningConfirm(title, message, onConfirm)` / `confirmWarningAction()` / `closeWarningConfirmModal()`
- `z-index: 1080` to stack above the setup baseline modal
- Handles both sync and async callbacks (same as delete modal)

**Include in:** `baseline-schedule.blade.php` (line ~481, where delete-confirmation-modal is already included).

#### 2. Frontend — `public/js/admin/projects/baseline-schedule-setup-modal.js`

**A. `resetSetupForm()` (~line 1662) — Dynamic month/date constraints:**

After existing dateInput.min/max setup (lines 1671-1681), add logic:

```
Compute effectiveMinMonth = max(current month, planned start month)
Compute endMonth = planned end date's YYYY-MM

If setupLocalMonths.length === 0 (FIRST TARGET):
  monthInput.min = effectiveMinMonth
  monthInput.removeAttribute('max')
  dateInput.min = max(today, planned start date)

Else (SUBSEQUARY TARGETS):
  latestLabel = last entry's month_label
  nextMonth = latestLabel + 1 month (handle Dec→Jan rollover)

  If nextMonth > endMonth:
    Disable "Add Month" button (opacity 0.5, cursor not-allowed)
    Show message: "All months up to the planned end date have been added."
    Return early

  monthInput.min = nextMonth
  monthInput.max = nextMonth  ← locks to exactly one month
  dateInput.min = first day of nextMonth
  dateInput.max = min(last day of nextMonth, bounds.max)
```

**B. `onSetupBaselineDone()` (~line 768) — Save-time warning:**

Before the fetch call (in all 3 save paths), add warning check:

```
Get startMonth from bounds.min → YYYY-MM
Get endMonth from bounds.max → YYYY-MM
Get first month from setupLocalMonths[0].month_label
Get last month from setupLocalMonths[last].month_label

warnings = []
if first month ≠ start month → push warning
if last month ≠ end month → push warning

if warnings.length > 0:
  showWarningConfirm('Baseline Month Mismatch', warnings HTML, function() {
    proceedWithSave()  // user confirmed — continue
  })
  return  // pause until user responds

proceedWithSave()  // no warnings — proceed directly
```

The existing save logic (fetch + error handling) gets wrapped in `proceedWithSave()` to avoid duplication.

#### 3. Backend — No changes needed

The backend `storeInitialBaselineSetup()` already validates:
- All target dates within project start/end dates (lines 343-371)
- Months sorted chronologically (line 332)
- Progress strictly increasing (lines 373-388)

Frontend restrictions are stricter (constraining to next month only) but backend catches any out-of-bounds dates.

### Files to Create/Modify

| # | File | Change | ~Lines |
|---|------|--------|--------|
| 1 | `resources/views/components/warning-confirm-modal.blade.php` | **New** — Reusable warning confirmation modal (amber theme) | ~90 |
| 2 | `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php` | `@include` the new component | 1 |
| 3 | `public/js/admin/projects/baseline-schedule-setup-modal.js` | Dynamic month/date min/max in `resetSetupForm()` + disable "Add Month" when full + save-time warning in `onSetupBaselineDone()` | ~55 |
| 4 | `textfiles/bugs_and_behavior_adjustments_part2.md` | Log E13 | this entry |

### Effort

Medium — 1 new component (~90 lines) + ~55 lines in setup modal JS + 1 line Blade include.

---

## Session Log: September 1, 2026 (Updated)

### Changes Planned

| Item | Change | Files | Status |
|---|---|---|---|
| E12 | Frontend date constraints + backend symmetric rule | `_create_project_modal.js`, `ProjectController.php` | IMPLEMENTED |
| E13 | Baseline setup month/date restrictions + save-time warnings | `warning-confirm-modal.blade.php` (new), `baseline-schedule-setup-modal.js`, `baseline-schedule.blade.php`, `admin/projects.blade.php`, `projects/index.blade.php` | IMPLEMENTED |

### Implementation Details

**Frontend** (`public/js/_create_project_modal.js`):
- Added `plannedStartDate` change handler → sets `plannedEndDate.min` (gray out dates before start)
- Added `plannedEndDate` change handler → sets `plannedStartDate.max` (gray out dates after end) + `projectHandoverDate.min` (enforce handover after end)

**Backend** (`app/Http/Controllers/ProjectController.php`):
- `store()` line 609: `planned_start_date` rule changed from `['nullable', 'date']` to `['nullable', 'date', 'before_or_equal:planned_end_date']`
- `update()` line 999: same change

**E13 — Baseline Setup Modal** (`public/js/admin/projects/baseline-schedule-setup-modal.js`):
- `resetSetupForm()`: Dynamic month/date min/max constraints
  - First target: month picker min = max(today, planned start month), no max
  - Subsequent targets: month picker locked to next month only (min=max)
  - When next month exceeds planned end month: disables "Add Month" button
- `onSetupBaselineDone()`: Refactored to `performBaselineSave()` + warning check via `getBaselineMonthWarnings()`
  - Checks first month vs planned start month, last month vs planned end month
  - Shows `warning-confirm-modal` if mismatches found; user confirms to proceed or cancels
- Extracted `resetActivitiesContainer()` helper for reuse

**E13 — New Component** (`resources/views/components/warning-confirm-modal.blade.php`):
- Amber/orange themed confirmation modal (same pattern as delete-confirmation-modal)
- Global JS: `showWarningConfirm(title, message, onConfirm)` / `confirmWarningAction()` / `closeWarningConfirmModal()`
- z-index 1080 to stack above setup baseline modal
- Included in: `baseline-schedule.blade.php`, `admin/projects.blade.php`, `projects/index.blade.php`

### Implementation Order

1. ~~**E12** (date restriction on create project modal)~~ — IMPLEMENTED
2. ~~**E13** (baseline setup modal month/date restrictions + save-time warnings)~~ — IMPLEMENTED

### Session Log: September 1, 2026 (E13 Fix)

**User feedback:** With planned_start = Aug 1 and current = Sep 1, the system only allowed Sep 2026+ for the first month. Expected: Aug 2026+ (planned start month).

**Root cause:** `effectiveMinMonth` used `max(currentMonth, startMonth)` which always picked the later of current/start. For a project that started in Aug but is being set up in Sep, this blocked Aug.

**Fix:** Changed logic to:
- If current > planned end → min = planned end (overdue project)
- If current < planned start → min = current (plan from today)
- Else → min = planned start (plan from project start, regardless of current date)

### Session Log: September 1, 2026 (E13 Bug Fix — Edit Button Blocked)

**Problem:** After auto-generating S-curve (which fills all months up to planned end), clicking "Edit" on a month changes the button text to "Update Month" but the button stays disabled.

**Root cause:** `resetSetupForm()` disables the "Add Month" button when `nextMonth > endMonth` (all months added). When user clicks "Edit", `editSetupBaselineMonth()` changes `innerHTML` and `style.background` but never sets `addBtn.disabled = false`. The button remains in its disabled state.

**Fix:** Added `addBtn.disabled = false`, `addBtn.style.opacity = '1'`, `addBtn.style.cursor = 'pointer'` in `editSetupBaselineMonth()` before setting innerHTML. Ensures button is always re-enabled when entering edit mode.

### Session Log: September 1, 2026 (E13 Bug Fix — Auto-Generate Button Missing After Remove)

**Problem:** After manually adding one monthly target then removing it, the auto-generate S-curve section doesn't reappear even though there are no months.

**Root cause:** `renderSetupMonthList()` has an early return (line 1454) for the empty state that exits before reaching `updateAutoGenVisibility()` (line 1549). So the auto-gen section's display is never updated back to `block` when all months are removed.

**Fix:** Added `updateAutoGenVisibility()` call before the early return in the empty-state branch of `renderSetupMonthList()`.

### Session Log: September 1, 2026 (E13 — Gap-Aware Month Adding)

**Problem:** After auto-generating an S-curve and removing a month (especially from the middle), the "Add Month" button stays disabled. The old logic only checked if the month after the *latest* added month exceeded the planned end, so mid-timeline gaps were unreachable.

**Root cause:** Two issues:
1. `resetSetupForm()` only calculated `nextMonth = latest + 1` — it didn't scan for missing months in the timeline.
2. `removeSetupBaselineMonth()` never called `resetSetupForm()` when the user wasn't in edit mode (no `else` branch after the `setupEditingIndex` checks).

**Fix (3 changes in `baseline-schedule-setup-modal.js`):**

1. **`resetSetupForm()` — subsequent-targets branch (line ~1810):** Replaced the sequential `nextMonth = latest + 1` logic with gap-aware logic:
   - Calculates all expected months from `planned_start` to `planned_end` (inclusive of both endpoints).
   - Collects existing month labels from `setupLocalMonths`.
   - Finds the **first missing month** in chronological order.
   - If a gap exists: locks the month picker (min=max) and date picker to that missing month.
   - If no gaps remain: disables the Add Month button and shows the "all months added" message.

2. **`addSetupBaselineMonth()` — new-entry validation (line ~1058):** Replaced the "must be after all existing months" sequential check and the "must be > current max %" check with gap-aware validation:
   - Month must fall within the project timeline bounds.
   - Progress % must be strictly between the previous and next existing months' progress (found via sorted-neighbor scan). Default prev = 0%, next = 100%.

3. **`removeSetupBaselineMonth()` (line ~1176):** Added an `else` branch after the `setupEditingIndex` checks to call `resetSetupForm()` when not in edit mode, ensuring the Add Month button re-enables if gaps remain after removal.

### Session Log: September 1, 2026 (E13 — Clear All Baseline Months Button)

**Request:** User wanted a one-click button to remove all monthly targets at once.

**Changes:**
1. **`_setup_baseline_modal.blade.php`:** Added a "Clear All" button (`#setupClearAllBtn`) inline in the "Months Added" header. Red-themed, hidden by default, shown when months exist.
2. **`baseline-schedule-setup-modal.js`:** Added `clearAllSetupMonths()` function:
   - Shows `showWarningConfirm()` with a count-based message.
   - On confirm: tracks removed DB record IDs for server deletion (adjust/edit-original modes), empties `setupLocalMonths`, re-renders list, resets form, hides errors.
3. **`updateSetupProgress()`:** Added toggle for the Clear All button — `display: inline-flex` when months > 0, `none` when empty.

### Session Log: September 1, 2026 (E13 — Switch Discard Baseline Cancel to Warning Modal)

**Request:** Change the "discard unsaved baseline" confirmation (triggered by closing the setup modal with months) from the red delete-confirmation-modal to the amber warning-confirm-modal.

**Change:** `onSetupBaselineCancel()` (line ~706): replaced `showDeleteConfirmation(...)` with `showWarningConfirm(...)`. No other changes needed — the function signature and callback pattern are identical.

### Session Log: September 1, 2026 (E13 — Chronological Insert for Re-Added Gap Months)

**Problem:** After deleting a monthly target (e.g., Jan 2027) and re-adding it, the entry was appended to the end of the list instead of slotting into its correct chronological position between Dec 2026 and Feb 2027.

**Root cause:** `addSetupBaselineMonth()` used `setupLocalMonths.push(entry)` which always appends, ignoring `month_label` order.

**Fix:** Replaced `push(entry)` with a sorted insertion that scans `setupLocalMonths` for the first entry with a greater `month_label` and `splice`s the new entry at that index. This keeps the array always chronologically ordered. The existing edit path (`setupLocalMonths[setupEditingIndex] = entry`) is unaffected since it updates in-place.

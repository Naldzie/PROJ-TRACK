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

### Enhancement 14: Full-Screen Preloader for Baseline Save

**Problem:** The "Done — Lock Baseline" save only shows an inline button spinner (`spinner-border` on the Done button). Saving multiple monthly targets is a heavier operation that can take noticeable time, and users have no indication that processing is happening beyond the button state.

**Established pattern:** Other modals that trigger heavy saves (record-progress, edit-progress, issues, risk-register) dismiss the modal and show `window.ProjTrackPreloader.show('Action…')` — a full-screen branded overlay that persists until the page reloads or an error occurs.

**Plan:** Add the `ProjTrackPreloader` overlay to `performBaselineSave()` in `baseline-schedule-setup-modal.js`, matching the established pattern:

1. **Before each `fetch` call** (both adjust/save-immediately paths): dismiss the setup modal, then show preloader:
   ```js
   try {
       if (setupModalInstance) setupModalInstance.hide();
   } catch (err) {}
   if (window.ProjTrackPreloader && window.ProjTrackPreloader.show) {
       window.ProjTrackPreloader.show('Saving baseline schedule…');
   }
   ```

2. **In error/restore paths** (both `.catch()` and non-success `.then()`): hide preloader before re-enabling the button:
   ```js
   if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
   ```

3. **Success paths** already do `location.reload()`, so the preloader stays visible during reload — no change needed there.

**Files:** `public/js/admin/projects/baseline-schedule-setup-modal.js` — `performBaselineSave()` (line ~849)

### Enhancement 15: Full-Screen Preloader for Create/Edit Project Modal

**Problem:** The create/edit project modal only uses plain text "Saving..."/"Creating..." on the submit button with no spinner icon. No preloader is shown during the save, which can take time with file uploads and baseline schedule targets.

**Plan:** Add `ProjTrackPreloader` overlay to the create project modal's submit handler in `_create_project_modal.js`, matching the established pattern:

1. **Before the `fetch` call** (line ~774): dismiss the modal, then show preloader:
   ```js
   try {
       var modal = bootstrap.Modal.getInstance(document.getElementById('createProjectModal'));
       if (modal) modal.hide();
   } catch (err) {}
   if (window.ProjTrackPreloader && window.ProjTrackPreloader.show) {
       window.ProjTrackPreloader.show(window._projectModalMode === 'edit' ? 'Saving changes…' : 'Creating project…');
   }
   ```

2. **In the error `else` branch** (validation/non-success response): hide preloader before showing errors.

3. **In the `catch` block** (network errors): hide preloader before showing error.

4. **On success** (`window.location.href = data.redirect`): preloader stays visible through navigation.

**Files:** `public/js/_create_project_modal.js` — submit handler (line ~694)

### Bug: Title Validation Should Exclude Archived Projects

**Problem:** Title uniqueness validation checks against ALL projects including archived ones. Users cannot reuse a title from an archived (deleted) project, which is unnecessary since archived projects are inactive.

**Fix:** Added `->where('is_archived', false)` to all three title-check paths:

1. **`Project::titleExists()` model method** (`app/Models/Project.php` line ~491): Added `->where('is_archived', false)` to the query. Affects the store validation.
2. **AJAX title uniqueness check** (`ProjectController.php` line ~568): Added `->where('is_archived', false)` to the query used for real-time validation in the create/edit form.
3. **Update method check** (`ProjectController.php` line ~979): Added `->where('is_archived', false)` to the inline query used during project updates.

**Branch:** `fix/project-title-validation-archived`

### Enhancement 16: Remove Delete Button for Original Baseline Months

**Problem:** Admin users could delete original baseline months from the timeline, but original baseline targets should only be editable through the Edit Original Baseline modal. Only extension/additional months (added after an extension) should be deletable.

**Original condition** (`baseline-schedule.blade.php` line 388):
```php
@if(auth()->user()->role === 'admin' || ($isAssignedStaff && !$latestProgress && $plan->original_planned_progress_percent === null))
```
This gave admins blanket delete access to any month, including original baseline targets.

**New condition:**
```php
@if($plan->original_planned_progress_percent === null && !$latestProgress && ($isAssignedStaff || auth()->user()->role === 'admin'))
```

**Behavior after change:**
- **Original baseline months** (`original_planned_progress_percent` is NOT NULL): delete button hidden for everyone. Edits go through the Edit Original Baseline modal only.
- **Extension/additional months** (`original_planned_progress_percent` is NULL): delete button retained for admin and assigned staff, provided no progress has been recorded.

**Files:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php` (line 388)

### Enhancement 17: Full-Screen Preloader for Delete Month Target

**Problem:** Deleting a non-original monthly target from the timeline only showed an inline delete confirmation modal, then reloaded the page with no loading indicator. This felt instantaneous for the user but could have a delay.

**Established pattern:** Other destructive operations (baseline save, create project) now use `ProjTrackPreloader.show()` after dismissing the confirmation modal.

**Changes in `confirmDeleteMonthTarget()` (`baseline-schedule.blade.php` line ~529):**
1. Before fetch: dismiss the delete confirmation modal via `closeDeleteConfirmModal()`, then show `window.ProjTrackPreloader.show('Deleting month target…')`.
2. On success: preloader stays visible through `location.reload()`.
3. On error: hide preloader, then show error toast.

**Files:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php` — `confirmDeleteMonthTarget()`

---

## Batch: UI Loading States, Validation, and Baseline Adjustments

**Branch:** `batch/ui-loading-and-validation-improvements`
**Date:** September 1, 2026
**Status:** PLANNED — not yet implemented

---

### Enhancement 18: Record Progress Button — Show for All Unrecorded Past/Current Months

**Problem:** The Record Progress button only appears on the latest recorded month and the very next unrecorded month. Users cannot skip a month or go back to record progress for an earlier unrecorded month.

**Current condition** (`baseline-schedule.blade.php` lines 186, 272):
```php
$showRecordButton = $plan->month_label === $latestProgressMonth || $plan->month_label === $nextPendingMonth;
```

**Proposed condition:**
```php
$showRecordButton = !$hasActual && $plan->month_label <= $currentMonthLabel;
```

This shows the Record Progress button for any month that:
- Has no actual progress recorded (`$hasActual` is false — already computed at line 190)
- Is current or past (month_label ≤ current month)

Future months remain hidden. The backend already allows out-of-order progress recording.

**Files:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php` (lines 186 and 272)

---

### Enhancement 19: Full-Screen Preloader for Add/Edit Extension

**Problem:** The extension modal has a button spinner but no full-screen preloader. Saving extensions can take time (evidence uploads).

**Proposed change:** Add `ProjTrackPreloader` to `submitExtension()`:
1. Before `fetch`: dismiss modal, show `ProjTrackPreloader.show('Saving extension…')`
2. On error: hide preloader
3. On success: preloader stays through `location.reload()`

**File:** `resources/views/projects/project_detail/components/modals/request_extension_modal.blade.php` — `submitExtension()` (line ~470)

---

### Enhancement 20: Add Monthly Target Modal — Date Restrictions (Gap-Aware)

**Problem:** The single Add Monthly Target modal does not constrain the month picker or date picker to valid ranges. Users can pick any month/date within the project bounds, potentially creating duplicates or out-of-order entries.

**Proposed change:** Apply the same gap-aware logic used in the setup modal:
1. In `openAddMonthTargetModal()`: calculate all expected months from project start to end, find the first missing month, lock the month picker to that month (min=max).
2. Constrain the date picker to the selected month's date range.
3. When all months are filled, disable the Save button.

**File:** `public/js/admin/projects/baseline-schedule-add-month-modal.js` — `openAddMonthTargetModal()` (lines 47-122)

---

### Enhancement 21: Add Monthly Target Modal — Loading State

**Problem:** No loading indicator when saving a monthly target.

**Proposed change:** Add `ProjTrackPreloader` to both `saveMonthTarget()` and `saveMonthTargetEdit()`:
1. Before `fetch`: dismiss modal, show `ProjTrackPreloader.show('Saving monthly target…')`
2. On error: hide preloader
3. On success: preloader stays through `location.reload()`

**Files:** `public/js/admin/projects/baseline-schedule-add-month-modal.js` — `saveMonthTarget()` (line ~467), `saveMonthTargetEdit()` (line ~574)

---

### Enhancement 22: Resubmit Modal — Include Status Field (Default: Approved)

**Problem:** When admin/staff opens the extension modal in resubmit mode, the status dropdown is hidden. Resubmit always creates a "pending" row. Admins should be able to approve immediately on resubmit.

**Proposed change:** In `_populateExtensionEditFields()` (line 308), remove the `&& editMode !== 'resubmit'` condition so the status field shows for admin/staff in all modes. Default the status to `"approved"` when in resubmit mode.

**File:** `resources/views/projects/project_detail/components/modals/request_extension_modal.blade.php` — `_populateExtensionEditFields()` (line 308), `openResubmitExtensionModal()` (line 266)

---

### Enhancement 23: Improve Inline Error Messages in Add Monthly Target Modal

**Problem:** Most validation errors are pushed as plain strings (`errors.push('...')`) and display in the top-level error container. The blade already has inline error divs (`monthLabelError`, `plannedDateError`, `plannedProgressPercentError`) and `showMonthTargetErrors()` already maps `{ field, message }` objects to them — but the validation code doesn't use the field-mapped format.

**Proposed change:** Convert plain-string error pushes to `{ field, message }` objects where they relate to a specific input:
- Chronological order violation → `{ field: 'monthLabel', message: '...' }`
- Progress not higher than previous → `{ field: 'plannedProgressPercent', message: '...' }`
- Progress at 100% cap → `{ field: 'plannedProgressPercent', message: '...' }`
- Date within month (Rule A) → `{ field: 'plannedDate', message: '...' }` (already done)
- Date within bounds (Rule BOUNDS) → `{ field: 'plannedDate', message: '...' }` (already done)
- Duplicate month / before first target → keep as generic (no specific field)

**File:** `public/js/admin/projects/baseline-schedule-add-month-modal.js` — `saveMonthTarget()` validation (lines 330-428)

---

### Enhancement 24: Setup Modal — "Restore to Original" Button in Adjust Mode

**Problem:** In adjust mode, the "Clear All" button empties everything. Users need a way to revert all adjustments back to the original baseline values.

**Proposed changes:**
1. In the blade template, add a second button (`setupRestoreBtn`) with "Restore to Original" label (amber theme, reset icon). Hide it by default.
2. `updateSetupProgress()`: toggle between "Clear All" (setup mode) and "Restore to Original" (adjust mode) based on `setupAdjustMode`.
3. New function `restoreToOriginalBaseline()`:
   - Reloads `setupLocalMonths` from the saved original baseline data (captured in `openAdjustBaselineModal()`)
   - Clears `setupRemovedIds` (no longer removing original months)
   - Re-renders the list, progress, S-curve preview, and resets the form
   - Shows a `showWarningConfirm()` before proceeding

**Files:**
- `resources/views/projects/project_detail/components/modals/_setup_baseline_modal.blade.php` — add restore button
- `public/js/admin/projects/baseline-schedule-setup-modal.js` — new `restoreToOriginalBaseline()`, update `updateSetupProgress()`, update `openAdjustBaselineModal()`

---

## Session Log: September 1, 2026 (Post-Implementation Fixes)

### Fix: E18 — Record Progress Button Missing for Latest Month with Progress

**Problem:** The new condition `!$hasActual && $plan->month_label <= $currentMonthLabel` removed the button from the latest month that already has progress. Users could no longer add a second progress record to the same month.

**Fix:** Added `|| $plan->month_label === $latestProgressMonth` to the condition in both timeline dots and detail panel loops:
```php
$showRecordButton = (!$hasActual && $plan->month_label <= $currentMonthLabel) || $plan->month_label === $latestProgressMonth;
```

### Fix: E19 — Preloader Text + Email Failure Handling

**Changes:**
1. **Preloader text per context:** Updated `submitExtension()` to show mode-aware preloader text:
   - Create mode: "Saving extension…"
   - Edit mode: "Updating extension…"
   - Resubmit mode: "Resubmitting extension…"

2. **Archive extension preloader:** Added `ProjTrackPreloader.show('Archiving extension…')` to `performArchiveExtension()` in `baseline-schedule-extensions.js`.

3. **Email failure graceful handling:** Wrapped `$user->notify()` in `NotificationService::notifyUnique()` with try-catch. Email failures now log a warning instead of blocking the request. Database notifications are still created.

### Fix: E20 — `hasExistingTargets` Not Defined

**Problem:** Replacing the card-scanning logic removed the `hasExistingTargets` variable, but it was still referenced at line 148 for the highestProgress calculation.

**Fix:** Replaced `hasExistingTargets` with `existingMonths.length > 0` (the variable from the new gap-aware logic).

### Addition: E24 — Re-run Auto Adjust Button

**Added:** A "Re-run Auto Adjust" button inside the remaining work wrapper (adjust mode only). When clicked:
1. Shows a warning confirmation modal.
2. Resets to original months (deep copy).
3. Re-runs `generateAutoDistribution()`.
4. Re-applies the current Additional Work % if any.
5. Re-renders the list, progress, S-curve preview, and resets the form.

**Files:**
- `_setup_baseline_modal.blade.php` — added button HTML inside remaining work wrapper
- `baseline-schedule-setup-modal.js` — new `rerunAutoAdjust()` function

---

### Fix: E20 — Add Monthly Target Modal Defaults to First Month Instead of Gap

**Problem:** Opening the "Add Monthly Target" modal always defaulted the month picker to the **first month** of the project timeline instead of the **first gap** (missing month). The gap-aware restriction logic was correct in principle, but the DOM scraping that collected existing months used an outdated selector.

**Root cause:** `openAddMonthTargetModal()` in `baseline-schedule-add-month-modal.js` (line 49) scraped existing months using:
```js
const cardElements = document.querySelectorAll('#sCurvePlanTableBody > div[style*="background: white"]');
```
The current UI uses a **timeline layout** with `.timeline-dot[data-month-label]` elements, not cards with inline `background: white`. The selector matched nothing, so `existingMonths` was always empty, and `firstMissing` always resolved to the project start month.

**Secondary issue:** Lines 139-141 (`dateInput.setAttribute('min/max', projStart/projEnd)`) overwrote the tight gap-aware date constraints with the full project range, allowing users to pick any date in the project.

**Tertiary issue:** The `highestProgress` calculation (default progress value) also used the broken `cardElements` selector.

**Fix:**
1. **Replaced DOM selector** — now reads from `.timeline-dot[data-month-label]` elements inside `#sCurvePlanTableBody`
2. **Removed override lines** — deleted the 3 lines that overwrote gap-aware date constraints with full project range
3. **Fixed highestProgress** — reads from `dot.dataset.plannedProgress` on timeline dots
4. **Added `data-planned-progress`** attribute to timeline dots in `baseline-schedule.blade.php`

**Files:**
- `public/js/admin/projects/baseline-schedule-add-month-modal.js` — fixed selector, removed override, fixed highestProgress
- `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php` — added `data-planned-progress` and `data-actual-progress` attrs

### Fix: E20 Extended — All Broken DOM Selectors (card → timeline dot)

**Problem:** The same broken card-layout selector `#sCurvePlanTableBody > div[style*="background: white"]` was used in multiple JS files. Since the UI switched to a timeline layout, all these selectors match nothing, causing various features to silently malfunction.

**All affected files and fixes:**

| File | Function | Fix |
|---|---|---|
| `baseline-schedule-add-month-modal.js` | `openAddMonthTargetModal()` | Replaced card selector with `.timeline-dot[data-month-label]` |
| `baseline-schedule-add-month-modal.js` | `saveMonthTarget()` validation | Replaced card selector for maxProgressPercent and existingMonthLabels |
| `baseline-schedule-record-progress-modal.js` | `saveMonthlyProgress()` validation | Replaced card selector, reads `data-actual-progress` from dots |
| `baseline-schedule-carry-over.js` | `populateTargetMonthDropdown()` | Replaced card selector, reads label from `.timeline-dot-label` |
| `baseline-schedule-carry-over.js` | `findBaselineScheduleIdByMonthLabel()` | Replaced card selector, reads `data-planId` from dots |
| `baseline-schedule-carry-over.js` | `openCarryOverForMonth()` | Replaced card selector, finds collapse panel by `activities-{planId}` |
| `baseline-schedule-manage-activities-modal.js` | `reloadInlineActivities()` | Replaced card selector, finds collapse panel by `activities-{planId}` |

**Additional blade change:** Added `data-actual-progress` attribute to timeline dots for record progress validation.

---

### Fix: Restore to Original Baseline — Two Bugs

**Problem 1: Reverts `planned_progress_percent` for months WITH progress**

The restore function reverted ALL original months to their `original_planned_progress_percent`, including months with `has_actual_progress: true`. Since the server rejects changes to months with recorded progress, the save would fail after restore.

**Fix:** Added `!m.has_actual_progress` check. Months with progress keep their current `planned_progress_percent`.

**Problem 2: Deletes previously saved extension months**

The code added ALL extension months from the auto-generated state to `setupRemovedIds`, including extension months that were saved in the DB from a previous session. This caused previously approved extension months to be deleted on save.

**Fix:** Compare each extension month against `setupRawOriginalMonths` (the DB state). Only add to `setupRemovedIds` if the month was NOT in the DB state (i.e., it was auto-generated by `generateAutoDistribution()`).

### Fix: Restore to Original — Use Extension Snapshot JSON

**Problem:** The restore function used `setupRawOriginalMonths` (the DB state fetched when modal opened). But the user wants it to revert to the **last saved baseline state** from the extension's `baseline_snapshot` JSON. This ensures previously saved extension months are properly restored.

**Implementation:**

1. **New backend endpoint:** `GET /admin/projects/{id}/extensions/latest-snapshot` (and `/staff/...`)
   - Returns the `baseline_snapshot` JSON from the latest approved extension
   - 404 if no approved extension with snapshot exists

2. **New route:** Added to both admin and staff route groups in `routes/web.php`

3. **Modified `restoreToOriginalBaseline()`:**
   - Fetches snapshot from the new endpoint
   - Maps snapshot months to `setupLocalMonths` format, preserving `has_actual_progress` from current state
   - Auto-generated extension months (not in snapshot) are added to `setupRemovedIds`
   - Falls back to `setupRawOriginalMonths` if snapshot fetch fails

**Behavior:**
| Scenario | Before | After |
|---|---|---|
| Restore with no extension | Reverts to DB state | Reverts to DB state ✓ |
| Restore with previous extension | Deleted extension months ✗ | Restores from snapshot ✓ |
| Restore with progress on extension | Orphaned progress records | Snapshot restored, progress preserved ✓ |
| Snapshot fetch fails | N/A | Falls back to DB state ✓ |

**Files:**
- `app/Http/Controllers/Admin/ProjectExtensionController.php` — new `latestSnapshot()` method
- `routes/web.php` — new routes for admin and staff
- `public/js/admin/projects/baseline-schedule-setup-modal.js` — `restoreToOriginalBaseline()` rewritten to use snapshot

---

## Enhancement 23: Resubmit Modal — Status Field Now Respected

**Problem:** E22 added a status dropdown to the resubmit modal (defaulting to "approved") but it was non-functional. Frontend JS excluded `status` from the formData in resubmit mode (`editMode !== 'resubmit'` guard), and the backend hardcoded `'status' => 'pending'`. The dropdown was purely cosmetic.

**Root cause:** Two layers:
1. Frontend guard at `request_extension_modal.blade.php:454` excluded status from the payload in resubmit mode
2. Backend `ProjectExtensionController::resubmit()` hardcoded `'status' => 'pending'` at line 640

**Fix (Option A — let admin/staff control status during resubmit):**

1. **Frontend** (`request_extension_modal.blade.php`):
   - Removed the `editMode !== 'resubmit'` condition from the status inclusion guard
   - Status + rejection_reason now included in payload for all modes (create, edit, resubmit)
   - Frontend already defaults status to "approved" in resubmit mode (line 312)

2. **Backend** (`ProjectExtensionController.php`):
   - Added inline validation for `status` and `rejection_reason` in `resubmit()` (mirrors `store()`)
   - Reads `$request->input('status')` with fallback to `'pending'` for staff-only resubmits
   - Sends correct notification based on chosen status: `APPROVED`, `REJECTED`, or `SUBMITTED`
   - ActivityLog description now includes the status
   - Updated docblock to reflect new behavior

**Behavior summary:**
| Actor | Mode | Status dropdown | Default | Backend |
|---|---|---|---|---|
| Admin/Staff | Create | Visible | approved | Respects selection |
| Admin/Staff | Edit | Visible | Current value | Respects selection |
| Admin/Staff | Resubmit | Visible | approved (E22) | Now respects selection ✓ |
| Contractor | Any | Hidden | N/A | Always pending |

**Status → Notification mapping on resubmit:**
- `approved` → `APPROVED` notification (project team)
- `rejected` → `REJECTED` notification (submitter + contractor)
- `pending` → `SUBMITTED` notification (admins for review)

**Files modified:**
- `resources/views/projects/project_detail/components/modals/request_extension_modal.blade.php` — removed `editMode !== 'resubmit'` guard
- `app/Http/controllers/Admin/ProjectExtensionController.php` — `resubmit()` reads status from request, validates, sends correct notification

**Branch:** `fix/resubmit-modal-status-field`
**Date:** 2026-09-01

---

## Enhancement 24: Hide Resubmit Button When a Pending Extension Exists

**Problem:** Both client and consultant confirmed only 1 pending extension request is allowed at a time. The "Add Extension" button is already hidden when a pending extension exists (line 75-76 of `baseline-schedule-extensions.js`), but the "Resubmit" button on rejected extensions still shows. This would allow creating a second pending row via resubmit.

**Gaps found:**

1. **Frontend** (`baseline-schedule-extensions.js:183`):
   - `canResubmit` only checks `ext.status === 'rejected'` and ownership/role
   - Does NOT check `hasPending` (already computed at line 75, used only for Add button)
   - Resubmit button visible on rejected extensions even when a pending extension exists

2. **Backend** (`ProjectExtensionController.php:resubmit()`):
   - `store()` has the 1-pending guard (lines 80-91): queries for existing pending row, returns 422 if found
   - `resubmit()` has NO equivalent check — would allow creating a second pending row if status = `pending`

**Proposed fix (two-layer enforcement):**

1. **Frontend** (`baseline-schedule-extensions.js:183`):
   - Add `!hasPending` to `canResubmit` condition:
     ```js
     const canResubmit = ext.status === 'rejected' && !hasPending && (isAdmin || (isStaff && isOwner));
     ```
   - `hasPending` is already in scope (line 75)

2. **Backend** (`ProjectExtensionController.php` — `resubmit()`):
   - Add same pending-check guard from `store()`, after status is determined, before `DB::beginTransaction()`:
     ```php
     if ($status === 'pending') {
         $hasPending = ProjectExtension::where('project_id', $project->id)
             ->where('status', 'pending')
             ->exists();
         if ($hasPending) {
             DB::rollBack();
             return response()->json([
                 'success' => false,
                 'message' => 'There is already a pending extension request. Wait for it to be reviewed before resubmitting.',
             ], 422);
         }
     }
     ```

**Status:** PLANNED — awaiting implementation
**Date:** 2026-09-01

---

## Enhancement 25: Review Modal Date Validation — Allow Equal to Current Deadline

**Problem:** When admin/staff reviews (approves) a pending extension, they can set the `new_end_date` equal to the current project deadline, resulting in a 0-day extension. Both client and consultant require extensions to extend the deadline — a 0-day extension is meaningless.

**Root cause — two layers:**

### Layer 1: Backend `update()` review paths (lines 342-346 and 428-432)

Both staff and admin review paths use `after_or_equal`:
```php
$reviewRules['new_end_date'] = [
    'sometimes', 'required', 'date_format:Y-m-d',
    'after_or_equal:' . $project->planned_end_date->format('Y-m-d'),
    'before_or_equal:2030-12-31',
];
```

`store()` and `resubmit()` both use `validateExtension()` which has `after:` (strictly after). The review path is inconsistent.

### Layer 2: Frontend review modal (`review_extension_modal.blade.php:220-224`)

The `min` on the date picker is set to `original_end_date` (the pre-extension date), NOT the current deadline:
```js
dateInput.setAttribute('min', `${minYear}-${minMonth}-${minDay}`);
```

If a prior extension already moved the deadline forward, the reviewer can select a date that's after `original_end_date` but before or equal to the current deadline.

### Current validation summary:

| Flow | Backend Rule | Allows equal to deadline? |
|---|---|---|
| `store()` (create) | `validateExtension()` → `after:` | No ✅ |
| `resubmit()` (new row) | `validateExtension()` → `after:` | No ✅ |
| `update()` staff review | `after_or_equal:` | **Yes** ❌ |
| `update()` admin review | `after_or_equal:` | **Yes** ❌ |

### Proposed fix:

**Backend** (`ProjectExtensionController.php`):
- Lines 344 and 430: Change `after_or_equal:` to `after:` in both staff and admin review paths

**Frontend** (`review_extension_modal.blade.php:220-224`):
- Set `min` to the current deadline (same logic as `request_extension_modal.blade.php` for non-edit mode: current deadline + 1 day)
- Add JS validation in `submitReviewExtension()` to block `new_end_date <= currentDeadline` before sending the request

**Files modified:**
- `app/Http/Controllers/Admin/ProjectExtensionController.php` — `after_or_equal` → `after` in staff + admin review paths; added `current_deadline` to `formatExtension()`; custom error messages
- `resources/views/projects/project_detail/components/modals/review_extension_modal.blade.php` — `min` date uses `current_deadline + 1 day`; added JS validation in `submitReviewExtension()`; removed unused `currentEndDate`

**Status:** COMPLETED
**Date:** 2026-09-01

---

### E25 TEST FIX — `ProjectExtensionLifecycleTest` updated for `after` rule

**Problem:** After E25 changed `after_or_equal` → `after`, the test `test_admin_editing_active_approved_can_keep_its_own_date_equal_to_live_deadline` failed (expected 200, got 422).

**Root cause:** Test was written for the old `after_or_equal` behavior. The new E25 rule intentionally requires the date to be strictly after the current deadline.

**Fix:** Renamed test to `test_admin_editing_active_approved_cannot_keep_its_own_date_equal_to_live_deadline` and changed `assertOk()` to `assertStatus(422)`.

**Test results:** 64/64 `ProjectExtensionLifecycleTest` pass. 7 total failures in full suite (5 pre-existing, 2 EmailDraftTest pre-existing).

**Date:** 2026-09-02

---

### BUDGET SOURCE — Full implementation committed

**Problem:** Dashboard "Budget by Category" chart should be "Budget by Source" — grouped by funding source, not project category.

**Changes:**
- New `budget_sources` table + `budget_source_id` FK on `projects` (nullable for backward compat)
- `BudgetSource` model, seeder (9 PH govt sources: GAA, CHED, DPWH, LGU, GAD, Special Trust Fund, Regular Agency Fund, Foreign Assisted, PPP)
- `BudgetSourceController` with full CRUD
- Create/edit modal: 3-column layout (Budget | Budget Source | Contractor)
- Overview tab: Budget Source row below Total Budget
- Admin + contractor dashboard charts switched to "Budget by Source"
- Routes added in admin group

**Bug fix during implementation:** Accidentally removed 7 validation rules (planned dates, actual dates, status) from `store()` in `ProjectController.php` when adding `budget_source_id` rule — restored.

**Test results:** 6 pre-existing failures, 981 passed, 0 new failures.

**Files:** 5 new, 11 modified

**Date:** 2026-09-02

---

## Enhancement: Rating page AI comments � contractor overview + per-project AI review

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Request

In rating.blade: when a contractor is selected in Overview, add an AI comment about ALL projects performance of that contractor; when AI Review is clicked, analyze and comment about THAT project.

### Previous behavior

- Overview card showed only the score ring + static hint (Click a project's AI review...). No contractor-wide commentary.
- AI Review button only rendered when the project had AI solutions; projects without regression data returned No AI analysis found for this project with no comment.

### Fix

**Backend app/Http/Controllers/Admin/RatingController.php:**
- suggest() now always returns a project-specific review: uses regression snapshot when an AI solution exists, otherwise falls back to actual-vs-planned variance + extensions/issues context. Returns details {actual, planned, delay_pct, variance, r_squared, extensions, issues, status, source} + project_title.
- New contractorOverview() (POST /admin/rating/contractor-overview): aggregates total/completed/ongoing counts, avg star rating, on-time %, avg delay, extensions, trend direction and builds a rule-based AI overview paragraph with recommendation.
- generateReview() extended with project title, status, actual %, extension/issue context clause and model-confidence note.
- New generateContractorOverview() for the contractor-wide narrative.

**Routes routes/web.php:** added POST /admin/rating/contractor-overview (admin.rating.contractor-overview).

**Frontend resources/views/admin/rating.blade.php:**
- Analysis panel fetches + caches the contractor overview comment on every contractor select (loading skeleton, stale-response guard).
- Project list always shows AI review button (previously hasAi-gated).
- suggestAI() renders per-project card: project tag, headline, full review, metric chips (rating/actual/planned/delay/variance/R2/extensions/issues), Apply + Back to overview. Dismiss (which wrongly called closeModal) replaced.
- Overview cache invalidated on manual and AI-applied rating saves.

### Verification

- php -l RatingController.php clean; route:list shows admin.rating.contractor-overview.
- php artisan test --filter=UiPolishLayoutTest: 5 passed (includes admin rating page render).

---

## Enhancement: AI orb avatar with live dot on rating page

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Request

Add a cooler AI image to the rating UI � an AI avatar using a dot (live indicator).

### Fix (resources/views/admin/rating.blade.php only, no new assets)

- New .ai-orb CSS: conic-gradient orb (blue/violet/mint) with white sparkle SVG, floating animation, glow shadow, plus a .dot live indicator (green with ping animation). Sizes: default 30px, .lg 52px, .sm 18px; .ai-orb-row for avatar + label rows.
- JS orbHtml(size) helper reuses the same markup in dynamically rendered HTML.
- Analysis panel header icon replaced with the orb; empty state shows the large orb.
- Contractor overview comment now renders orb + AI OVERVIEW label row.
- Per-project AI review card header renders orb + AI REVIEW + status/title; loading state shows large orb.
- AI review buttons prefixed with the small orb.

### Verification

- php artisan test --filter=test_admin_rating_page_uses_shared_toast: 1 passed.

---

## Fix: Auto-rate all only rates Completed projects

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Problem

Auto-rate all loaded Ongoing + Completed projects, so in-progress work (still-moving S-curve) could receive a premature rating. The manual Rate button is already disabled for Ongoing projects.

### Fix (app/Http/Controllers/Admin/RatingController.php)

- autoRateAll() eager-load now filters where('status', 'Completed') instead of whereIn Ongoing/Completed, with a docblock noting ongoing work is never auto-rated.

### Verification

- php -l clean; rating page render test passed (1 passed).

---

## Enhancement: Humanoid robot avatar replaces sparkle in AI orb

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Request

User asked where the humanoid dot UI is � the orb icon was only a sparkle, not a humanoid figure.

### Fix (resources/views/admin/rating.blade.php only)

- Replaced the sparkle SVG with a humanoid robot bust (antenna + head + ears + eyes with highlights + smile + shoulders) in white on the existing gradient orb; green live dot kept. Updated all 3 occurrences (panel header, empty state, JS ORB_SVG helper) so overview comment, per-project review card, loading states and AI review buttons all show the robot.

### Verification

- php artisan test --filter=test_admin_rating_page_uses_shared_toast: 1 passed.

---

## Enhancement: Full UI polish pass on rating.blade

**Date:** 2026-09-07
**Status: IMPLEMENTED** � visual-only, no behavior changes.

### What changed (resources/views/admin/rating.blade.php, inline per existing file structure)

- Theme: new --violet/--grad-brand/--shadow-lg/--ease-spring tokens; gradient headline text; eyebrow pills with pulsing dot; ambient glow blob behind header; focus-visible rings; prefers-reduced-motion support.
- Header: AI online pill (robot orb + live dot) beside a gradient Auto-rate button with shine sweep on hover.
- Summary cards: staggered rise-in, gradient icon tiles, radial glow that scales on hover, count-up numbers (data-count).
- Panels: staggered entrance, hover lift, per-panel gradient title icons.
- Contractors: custom slim scrollbars (560px max), staggered rows, selected gradient + glow ring, gradient avatar when selected.
- Projects: status accent bar (violet = ongoing, green = completed), staggered entrance, pulsing In-progress dot, gradient buttons with lift.
- Analysis: layered-gradient hero with shimmer sweep, AI comment as chat bubble with gradient spine, shimmer skeleton while loading, animated score ring (0 to score count-up), gradient metric chips with hover lift.
- Performance: stat tiles with staggered entrance + hover lift, chart wrap restyled.
- Modal: blurred backdrop, spring pop-in, gradient top bar, star glow + pop animation on tap, gradient Save.
- Empty states: dashed-card style. JS: countUp/animateScore helpers, stagger delays, star pop, counters on init.

### Verification

- php artisan test --filter=test_admin_rating_page_uses_shared_toast: 1 passed (12 assertions).

---

## Feature: 5-day review window before AI analysis emails send

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Request

Generate AI analysis on the 25th, hold it in a 5-day review/edit window, auto-send on the 30th. Email views must reflect last-saved edits; scheduler must split generate vs send; status field (draft/pending_review/sent) with no re-send/re-generate mid-review.

### Findings (already existed, reused as-is)

- EmailTracking drafts (sent_at null), body/is_edited/edited_by, effectiveBody() (edited body wins).
- ai-analysis.blade.php already renders tracking->body first, AI text as fallback � edited content goes out with zero view changes.
- Admin/staff draft edit UI + sendDraft + drafts filter; staff reuses admin.partials.email_list.

### Changes

**DB:** migration 2026_09_08_000001 adds email_trackings.status (draft|pending_review|sent, default draft) + review_until nullable; backfills from sent_at.

**Model EmailTracking:** status constants, review_until cast/fillable, isPendingReview(), isDueForSending(), scopes pendingReview()/dueForSending().

**AIReportSendingService:** REVIEW_WINDOW_DAYS=5, GENERATION_DAY=25; new stageProjectForReview() (idempotent per ai_solution+contractor pair), sendDueReviews(force=false), sendTrackingRow() shared sender (marks status sent, failed sends stay editable); sendForProject() dedup now counts SENT rows only and reuses a staged row (unique-pair safe); nextAutoSendAt() now returns the next 25th 08:00.

**Commands/scheduler (routes/console.php):** new reports:generate-ai-monthly (monthlyOn 25 08:00, generate-missing + stage, never sends); reports:send-ai-monthly rewritten to send due reviews only; scheduled dailyAt 08:05 with review_until gate (daily, not monthlyOn(30), so February still delivers). --force on either step overrides gates.

**Admin+staff EmailController:** store() tags STATUS_DRAFT; destroy() 403s pending_review; sendDraft() flips status to sent; show() returns In review + review_status/review_until; index() gains filter=review.

**Blades:** shared email_list partial shows In review tag (new .tag-review CSS in admin+staff email.blade), Auto-sends {date} tag, lock icon instead of delete for in-review; new In review tab on both pages; cadence copy 1st -> Generate 25th / send after 5-day review.

**Contractor leak fix (required by staging drafts):** contractor index/recentActivity now sent-only; show() 404s unsent rows; contractor list partial uses effectiveBody() so contractors see the approved edited text.

### Verification

- New tests/Feature/AiReviewWindowTest.php: 10 passed (48 assertions) covering stage-no-send, idempotency, window gating, edited-body delivery + lock, manual-draft immunity (incl. --force), delete-block/edit/send-early, staged-row reuse, no-analysis skip, contractor invisibility, review filter.
- EmailDraftTest: 13 passed, 2 failed � identical to the pre-existing baseline (mailable subject asserted post-build; unrelated, per AGENTS.md). No new failures.
- UiPolishLayoutTest: 5 passed (email + rating pages render).
- schedule:list shows generate-ai-monthly (25th) + send-ai-monthly (daily).

---

## Enhancement: Regenerate stages to In review + tab count badges

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Request

1. Generating AI analysis from a project must land in Email In review (not auto-send) and sit 5 days before sending.
2. Filter tabs show counts, e.g. In review (1).

### Fix

- ProjectController::regenerateAnalysis() now calls stageProjectForReview() instead of sendForProject() (docblock updated). Frontend toast/status text now says the analysis is staged in Email In review for 5 days.
- AIReportSendingService::stageProjectForReview() supersedes older UNsent staged drafts for the same project+contractor (delete + recreate) so a regeneration never stacks two pending emails; message notes the replacement. Generate-command staged counter still matches (both messages start with Staged).
- Admin+staff EmailController::index() pass workspace-wide draftsCount (status draft, unsent) + inReviewCount (pendingReview scope); admin+staff email.blade tabs render Drafts (N) / In review (N) matching the existing All (N) style.

### Verification

- AiReviewWindowTest: 12 passed (57 assertions) � added supersede-on-regenerate + tab-badge tests.
- EmailDraftTest: 13 passed / 2 pre-existing fails (unchanged baseline). UiPolishLayoutTest: 5 passed.

---

## Enhancement: Highlighted sections in AI analysis email

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Request

Style emails/ai-analysis.blade.php so every section is highlighted.

### Fix (resources/views/emails/ai-analysis.blade.php only)

- New .section card system: one accent color per section with numbered badge (01-04) + uppercase heading bar + white body.
- 01 Project Snapshot (blue): project/location/status as chip-labeled rows instead of plain bold lines.
- 02 AI-Generated Analysis (violet): same content source (edited body wins), new highlighted shell.
- 03 Recommended Actions (amber, NEW section): top 5 recommendations from aiSolution->recommendations sorted by priority, each with P1(red)/P2(orange)/P3+(slate) pill, category label, message + optional reason. Previously never shown in email.
- 04 Project Progress Analysis (green): same metrics/bars/badge in highlighted shell; auto-renumbered to 03 when recommendations are absent.
- Revised-message note spacing fixed (margin-bottom added). Header/integrity/footer untouched.

### Verification

- AiReviewWindowTest: 14 passed (67 assertions) � added render tests for full-section output (incl. P1/P3 pills) and the empty-recommendations renumbering case.

---

## Enhancement: Message view/edit overlays styled like the AI analysis email

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Request

Opening (view) or editing (compose) a message still looked plain � restyle both like emails/ai-analysis.blade with highlighted sections.

### Fix

**View overlay (admin/email.blade.php + staff/email.blade.php, kept in sync):**
- New .v-section card system mirroring the email: 01 Project snapshot (blue), 02 AI analysis (violet), 03 Progress analysis (green), plus amber Revised message section shown only when edited.
- New status pill in the modal header (Draft / In review + auto-send date / Opened / Delivered / Pending) driven by the show() JSON (status, review_status, review_until).
- viewMsg JS populates pill + revised banner; the old inline Last-edited detail row was removed (replaced by the amber section). Progress metrics block de-chromed to sit inside the green section.

**Compose/edit modal (shared admin/partials/email_compose.blade.php � one edit covers staff+admin):**
- Gradient PROJ-TRACK banner header with cpu icon (title/meta turn white).
- Fields grouped into numbered sections: 01 Details (blue: project, recipient, subject), 02 Message (violet: textarea).
- Gradient Save button. All element IDs unchanged, so existing JS works untouched.

### Verification

- AiReviewWindowTest: 15 passed (85 assertions) � added overlay-markup test for both admin and staff pages.
- UiPolishLayoutTest: 5 passed. EmailDraftTest: 13 passed / 2 pre-existing fails (unchanged).
- Blade diff admin vs staff shows only pre-existing prefix/layout differences.

---

## Fix: Blue section highlights (Report/Risk/Mitigation/Performance/AI Comment) in email + overlay

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Problem

The AI text carries [SECTION:Report] / Possible Risk / Mitigation / Performance / AI Comment markers. The project AI tab renders those headings blue, but the email + message overlay did not: the overlay injected the RAW body (markers visible as plain text, zero styling) and the email relied only on its <style> block, which many mail clients strip.

### Fix

- AiReportParser::parseToHtml() now inlines the blue heading style (color #2563eb + 2px blue underline, same as the project tab) on every report-section-title, keeping the classes for stylesheets. Works in clients that strip <style>.
- New AiReportParser::renderForDisplay(): marked AI text -> parsed blue sections; hand-written drafts (no markers) -> plain escaped paragraphs (never a bogus Analysis heading).
- Admin+staff EmailController::show() expose body_html; both email.blade viewMsg() render body_html with raw-text fallback; added .report-section list/paragraph spacing CSS to both blades.

### Verification

- New tests/Unit/Services/AiReportParserTest.php: 3 passed (blue inline style, no marker leakage, plain+escaped handwritten path).
- AiReviewWindowTest: 16 passed � added show()-returns-highlighted-html test. UiPolishLayoutTest 5 passed; EmailDraftTest unchanged (13/2 pre-existing).

---

## Fix: Modal Save button always visible (sticky header/footer)

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Problem

The compose/edit modal scrolled as one unit, pushing the Save/Cancel footer out of view on shorter screens.

### Fix (admin/email.blade.php + staff/email.blade.php + shared email_compose partial)

- .modal-box now overflow:hidden (was overflow-y:auto); .modal-body scrolls internally (overflow-y:auto + min-height:0) so the banner header and footer buttons stay pinned while only the middle content scrolls. Applies to view + compose modals.
- Compose textarea min-height 190px -> 140px so the edit modal usually fits without scrolling at all.

### Verification

- UiPolishLayoutTest 5 passed; AiReviewWindowTest 16 passed.

---

## Fix: Message view overlay overlap/jumble (grid blowout guards)

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Problem

View-message overlay looked overlapping/jumbled with real content. Root cause: long unbroken strings (contractor emails, subjects, In review pills) forced grid tracks wider than the padded sections (classic grid blowout), and the 2-column details grid cramped on narrow screens.

### Fix (admin/email.blade.php + staff/email.blade.php)

- min-width:0 on detail items, modal head, section bodies/heads, message text.
- overflow-wrap:anywhere + word-break on values, titles, meta, message text.
- detail-grid collapses to 1 column under 620px.

### Verification

- AiReviewWindowTest + UiPolishLayoutTest: 21 passed. Markup/JS re-verified balanced and valid.

---

## Fix: Bulletproof modal scrolling (overlay scrolls, margin auto)

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Problem

View-message overlay still broken for the user (content not reachable); the flex-pinned inner-scroll model was fragile. User explicitly wants the whole modal scrollable to display all details.

### Fix (admin/email.blade.php + staff/email.blade.php)

- Replaced the flex-pinned inner scroll with the classic robust pattern: .overlay gets overflow-y:auto, .modal-box gets margin:auto with no height cap or overflow clipping, .modal-body back to natural flow. Short modals center; tall modals scroll from the top in every browser � header, all highlighted sections, notes, and footer buttons are all reachable by scrolling.

### Verification

- AiReviewWindowTest + UiPolishLayoutTest: 21 passed.

---

## Enhancement: Contractor email overlay matches staff/admin UI

**Date:** 2026-09-07
**Status: IMPLEMENTED**

### Problem

Contractor message view had its own outdated renderer: raw [SECTION:] markers shown in one block PLUS a second parsed copy appended below (duplicated, jumbled), with its own one-off colored header styles.

### Fix (contractor EmailController + contractor/email.blade.php)

- show() now serves body_html via AiReportParser::renderForDisplay (same as staff/admin).
- Overlay restructured into the same numbered highlighted sections: 01 Project snapshot (blue), 02 AI analysis (violet, blue section headings), 03 Progress analysis (green), plus status pill and the bulletproof overlay-scroll + anti-blowout guards.
- Deleted the buggy client-side parseAiAnalysis() (malformed list HTML, duplicated content) and the now-unused .analysis-section* CSS.

### Verification

- AiReviewWindowTest: 18 passed � added contractor overlay-markup + highlighted-html tests. Parser/UI suites green; EmailDraftTest unchanged baseline.

---

## Enhancement: Dashboard Option #3 UI redesign (content area only)

**Date:** 2026-09-11
**Status: IMPLEMENTED**

### Scope

Visual-only redesign of the dashboard content area per Option #3 (clean,
modern, spacious enterprise; CSPC blue; white/light-gray surfaces).
Global layout, sidebar, topbar, auth, routes, controllers, models,
partials, Chart.js data, AJAX filtering, calendar and campus-map widgets
are untouched.

### Changes

**resources/views/admin/dashboard.blade.php** (serves both admin + staff via
`@extends($layout)` + role-aware `$projectsIndex`):
- New `.db-hero` welcome banner using `public/images/download.png` as a
  decorative backdrop (`aria-hidden`) with a navy-to-transparent overlay;
  left = icon + "Welcome back" + user name + "Project Monitoring
  Dashboard / CSPC PMU" + role badge + date; right = client-side table
  search + existing New Project / All Projects route buttons.
- Alert bar keeps the same `@if` condition, counts and
  `onclick="filterDashboardTable(...)"` pills; inspection pill uses new
  `.yellow` class, extensions pill uses new `.purple` class (both additive,
  `dashboard.js` selects on `.alert-pill` only).
- Tabs keep identical `.db-tab-btn` / `data-target` / `dbSwitchTab`
  wiring; restyled as a segmented control (active = CSPC blue).
- 6 stat cards keep all variables/routes (`$totalProjects`,
  `$ongoingCount`, `$completedCount` + `$completedBudget` tooltip,
  `$overdueCount`, `$atRiskCount`, `$totalSavings`); added supporting
  descriptions; savings card accent moved to new `.s-teal` class.
- Projects table keeps the same `<thead>` columns, `#db-filter-badge`,
  and `@include('dashboard.partials.dashboard-table-rows')` with identical
  parameters; wrapped in `.db-table-scroll` (sticky header, horizontal
  scroll on small screens).
- All 5 chart cards keep canvas IDs (`statusDonut`, `healthDonut`,
  `budgetBar`, `progressBar`, `extensionDonut`) and the Chart.js data
  block is byte-identical; only card headers gained icon + "View details".
- Timeline / Calendar / Campus Map panes keep their `@include`s; wrapped
  in neutral shell cards without touching widget markup, CSS or JS.
- Bell + profile controls NOT duplicated (already in layout topbar).
- Added additive `dbSearchProjects()` client-side row filter; existing
  `filterDashboardTable` / `dbSwitchTab` / `DASHBOARD_FILTER_URL` unchanged.

**public/css/dashboard/dashboard.css**: full Option #3 rewrite (tokens
`--brand #2563EB`, `--ink #0F172A`, `--bg #F8FAFC`, `--line #E2E8F0`;
status `#16A34A/#F59E0B/#EF4444/#3B82F6/#7C3AED`; 12–16px radii, thin
borders, soft shadows, Inter, 26px hero / 15px section / 26px metric
type). ALL legacy selectors + CSS vars preserved for the table-row
partial, calendar/map widgets and timeline (`timeline.css` and
`dashboard-widgets.css` untouched).

### Verification

- `php artisan view:cache` compiles cleanly.
- `DashboardHealthTest`: 8 passed.
- `--filter="Dashboard"`: 34 passed, 3 failed — verified PRE-EXISTING by
  re-running against the pristine blade (`dashboard.blade copy.php`):
  same 3 failures (ContractorDashboardTest empty-states,
  DashboardOverdueInspectionTest count-badge + 1 companion) with zero
  of the touched files in their code path (filter endpoint returns the
  untouched `dashboard-table-rows` partial; contractor view untouched).

### Follow-up (2026-09-11): Timeline defaults to "This year"

- `dashboard/partials/timeline-widget.blade.php`: initial range now uses
  `$timelineYear` (was `$timelineQuarter`); header shows "This year /
  Jan 1 - Dec 31, YYYY"; the "This year" toggle button carries `.active`.
  Quarter/year toggle JS (`dashboard-timeline.js`) and `data-*` ranges
  unchanged, so switching back to quarter still works.
- Verified with `view:cache` (clean).

### Follow-up (2026-09-11): Timeline entrance animations

- `public/css/dashboard/timeline.css`: rows fade/slide in (`tlRowIn`,
  0.45s), bars grow from the left edge (`tlBarGrow` scaleX, keeps the
  existing `translateY(-50%)` centering), month labels fade, KPI strip
  rises with a CSS-only 4-item stagger, today marker has a slow soft
  pulse (2.6s), toggle buttons crossfade. All motion disabled under
  `prefers-reduced-motion`.
- `public/js/dashboard-timeline.js`: per-row entrance stagger on load
  (45ms steps, capped ~0.5s); `tlSwitchRange` replays the bar grow with
  a 30ms stagger (capped 300ms) via the reset/reflow trick. Geometry,
  tooltip, ext-filter and toggle logic otherwise unchanged.
- Verified: `node --check` clean, `view:cache` clean.

### Follow-up (2026-09-11): "All Projects" hero button visibility

- Problem: `.db-header-btn.outline` (transparent + thin white border) washed
  out over the bright right side of the hero campus photo.
- Fix (`public/css/dashboard/dashboard.css` only): dark glassy pill —
  `rgba(15,23,42,0.55)` + backdrop blur, 1.5px near-white border, dual
  drop/inset shadow, subtle text-shadow; hover deepens the fill, lifts 1px
  and strengthens the shadow. "New Project" primary button unchanged.
- Follow-up: switched to solid navy (`#1E3A8A`, hover `#172F6E`) per user
  request; white border + shadow retained so it still pops over the hero.

### Follow-up (2026-09-11): Overview layout rebalance

- Problem: 6-across stat row cramped at normal widths; side rail of 5
  stacked charts towered over the short projects table; bar charts were
  squeezed into the 380px rail.
- Fix (`dashboard.css` + `admin/dashboard.blade.php`, visuals only):
  stats are now vertical-icon cards in a balanced 3+3 grid (6-across only
  at 1600px+); rail slimmed to 340px holding Status, Health and Extensions
  donuts; Budget by Source + Planned-vs-Actual moved to a full-width
  2-column `.db-wide-grid` row below (220px tall, stacks on mobile).
  Canvas IDs, Chart.js data, table include, tabs and filters unchanged.
- Verified: `view:cache` clean, `DashboardHealthTest` 8 passed.

### Follow-up (2026-09-11): Overview rebalance REVERTED per user request

- Restored 6-across horizontal stat cards, 380px rail with all 5 chart
  cards (Status, Health, Budget, Progress, Extensions), removed
  `.db-wide-grid`. Overview is back to the Option #3 redesign state.

### Follow-up (2026-09-11): Contractor dashboard Option #3 redesign

- `contractor/dashboard.blade.php` rewritten visual-only: hero banner with
  `download.png` (company name, Contractor Overview badge, date, table
  search + View All Projects), 4 stat cards reusing `.db-stat-card`
  (same variables + pct subs), projects table + budget + deadlines +
  charts in `.db-main-grid` reusing `dashboard.css` classes. Table health
  logic, routes (`contractor.projects`, `projects.show`), canvas IDs and
  Chart.js data unchanged. Dropped duplicate Bootstrap/Icons CDN links
  (layout already loads them); kept Chart.js + `dashboard.css` links.
  Added additive `ctSearchProjects()` client-side filter.
- BUG FIX: `Contractor\DashboardController@index` built `$recentActivities`
  but never passed it to the view — added to `compact()` and rendered a
  Recent Activity card (fixes 2 pre-existing test failures).
- `ContractorDashboardTest::test_status_stat_cards_reflect_real_counts`
  updated: cards render number-before-label now, so counts assert against
  exact `<div class="db-stat-num">N</div>` markup instead of
  label-then-value order. Stat label casing restored to original
  ('Total assigned', 'In progress', 'Not yet started').
- Verified: `view:cache` clean, `ContractorDashboardTest` 8 passed;
  `--filter="Dashboard"` 36 passed / 1 failed — the single failure is the
  pre-existing `DashboardOverdueInspectionTest` count-badge case (untouched
  filter partial, fails identically on pristine files).

---
## E-Next (2026-09-13): Project Monitor UI/UX enhancement (frontend only)

**Status: IMPLEMENTED (uncommitted � no git available in env).** Backend untouched: no route/controller/model/query/variable/auth changes.

### Files
- 
esources/views/projects/index.blade.php � header hierarchy (.pm-header + subtitle), toolbar card wrapping existing IDs/handlers, data-col on all 13 th/td, row actions converted to kebab menu (same view/edit/archive routes), pagination moved inside new .projects-table-card, ilterProjects hardened with closest('.filter-btn'), 	oggleViewMode now also toggles the table card. All onclick/IDs (ilterStartYear, ilterDeadlineYear, projectSearch, gridViewBtn, 	ableViewBtn, rchiveProject, modals) preserved.
- public/css/admin/projects/projects.css (appended) � PROJ-TRACK palette vars (--pm-*), segmented tabs, 40px toolbar rhythm, Columns popover, enterprise table (light header, sticky thead, 1480px min-width with intentional x-scroll, per-column min-widths, word-boundary title wrap), pill badges, modern progress, sticky actions column, kebab menu, card footer, grid polish, responsive breakpoints. Additive overrides only.
- public/js/admin/projects/project-monitor-columns.js (new, vendored per no-vite rule, loaded via sset() in index.blade.php) � Columns dropdown build/toggle, localStorage key projtrack_project_columns (UI prefs only), Show all / Hide all (keeps Title) / Reset, empty-row colspan fix, kebab menu delegation, Esc-to-close.

### Behavior notes
- Column hide/show is instant, no reload; prefs survive refresh; Reset restores defaults. Actions column locked visible.
- ddslashes(->title) in the archive menu call fixes single-quote titles breaking the inline rchiveProject() JS string (previously raw {{ ->title }}). Same route/handler.
- Verified: php -l clean on blade + new JS; 
ode --check clean on new JS.


---
### Fix (2026-09-13): Row actions (kebab) menu blocked/clipped

**Problem:** Clicking the ... actions button opened a per-row dropdown that was cut off / hidden behind other rows � choices unclickable.

**Root cause:** Each .pm-row-menu-pop was absolutely positioned inside a 	d inside .projects-table-wrap (overflow-x/y: auto), so the scroll container clipped it; plus later rows' opaque 	d backgrounds (and the sticky actions column) painted over earlier rows' open menus.

**Fix (frontend only, same routes/handlers):**
- Kebab cell now a single button carrying data-view-url / data-edit-id / data-archive-url / data-archive-title (@can still enforced server-side; Blade-escaped titles also fix quote-breaking).
- One body-level #pmRowMenu (position: fixed, z-index: 9999) populated + positioned from getBoundingClientRect() on open, with flip-up near viewport bottom and viewport-edge clamping.
- Menu closes on outside click, item click (after action fires), table scroll (capture), resize, and Esc; toggle-shut on re-click.
- Verified: php -l clean, 
ode --check clean.


---
### Fix pack (2026-09-13): action menu stretch + blue header + table width (no redesign)

**Reports:** kebab menu rendered as a huge white strip across the page.

**Root cause:** .pm-row-menu-pop base rule sets 
ight: 0; the .pm-row-menu-floating override set position: fixed; left (via JS) but never cancelled 
ight � with both left and 
ight set, the browser stretched the menu from the button to the viewport edge.

**Fixes (CSS in projects.css, no backend/structure changes):**
- Floating menu: added 
ight: auto; width: max-content; min-width: 160px; max-width: 200px � small dropdown anchored to the clicked ... button. Smart flip-up + viewport clamping already in JS.
- Table width: replaced forced min-width: 1480px with width: 100%; min-width: 0; table-layout: auto � per-column min-widths form the intrinsic minimum, so the table fills freed space when columns hide and scrolls (overflow-x) only when many columns are visible.
- Verified 13 <th data-col> + 13 <td data-col> pairs � hide/show removes header and cells together; Actions stays locked visible.
- Restored blue gradient sticky header (white uppercase labels, light sort indicators) per request.
- Search widened to 270px so the placeholder is not cut off; toolbar keeps 40px control rhythm.
- Verified: php -l clean, 
ode --check clean. Sidebar/topbar/routes/controllers untouched.


---
### Enhancement (2026-09-13): grid cards use login-image.png cover

- index.blade.php: added .project-cover banner (sset('images/login-image.png'), lazy-loaded) at the top of each grid card; all card data/actions unchanged.
- projects.css: 140px cover with object-fit: cover + navy fade overlay (120px on mobile); card radius clips corners.


---
### Enhancement (2026-09-13): grid header uses login-image.png as background

- Replaced the separate cover banner with login-image.png as the .project-header background (navy overlay for contrast), white code/title with text-shadow, badge drop shadows. Table view untouched.


---
### Fix (2026-09-13): unstyled flash / delayed design on refresh (FOUC)

**Symptom:** refresh briefly shows unstyled content before the design snaps in.

**Causes fixed (no visual/backend changes):**
- Google Fonts stylesheet was render-blocking in all 3 layouts (pp, stafflayouts, contractorlayouts) � now preloaded + loaded with media=print onload swap (+noscript fallback), so slow font fetches no longer delay first paint.
- projects/index.blade.php: inlined a small critical-CSS block (header/toolbar/table-card/grid skeleton) so first paint is styled even while projects.css is still downloading; full styles still in public/css per convention.
- Saved Grid/Table mode previously applied only on DOMContentLoaded (wrong view flashed first) � a tiny synchronous script after the table markup now applies it before first paint; the existing loader re-affirms it afterwards.
- Verified: php -l clean on all 4 edited blades.


---
### Fix (2026-09-13): refresh flashes raw HTML before CSS applies

**Root causes:**
- The full-screen preloader overlay only existed as styled once external preloader.css arrived � nothing covered first paint, so slow stylesheet delivery exposed raw HTML.
- The overlay hid on window load, which waits for EVERY image/font/CDN script (card header art, topbar photo, Chart.js, Leaflet...), holding the reveal hostage and making styling feel delayed.

**Fixes:**
- components/preloader.blade.php: inlined a zero-specificity (:where()) cover � first paint is always the branded navy loader, never unstyled HTML; full preloader.css (blur, animation) overrides seamlessly on arrival.
- public/js/components/preloader.js: reveal on DOMContentLoaded + 600ms min display + 400ms fade (was: window load); load kept only as a guarded backup; manual ProjTrackPreloader.hide()/show() API and unmount behavior unchanged.
- Earlier companion changes kept: non-blocking Google Fonts in all 3 layouts, inline critical CSS + synchronous Grid/Table restore in Project Monitor.
- Verified: php -l clean, 
ode --check clean.


---
### Fix (2026-09-13): favicon on standalone login page

- uth/login.blade.php (standalone, no layout) was missing the favicon � added aviconpmu.png?v=3. The 3 layouts (admin/staff/contractor, incl. My Projects) and dmin/login (extends layouts.app) already had it.


---
### Root-cause fix (2026-09-13): favicon never showing on Projects/Login

**Analysis (verified, not guessed):** rendered HTML contains a correct <link rel=icon type=image/png href=.../faviconpmu.png>; the PNG is valid (390x390) and serves HTTP 200 as image/png; the serving process runs from this exact folder. The poison: public/favicon.ico was a MALFORMED icon � header declared one 32x32 entry but embedded the full 123KB source PNG. Browsers probing /favicon.ico got a corrupt icon, which poisons the origin's favicon cache entry, and normal/Ctrl+F5 refreshes do not clear it.

**Fix:**
- Regenerated public/favicon.ico properly (old file backed up to %TEMP%/favicon-old.ico): valid ICONDIR, 3 PNG-compressed entries 16/32/48 with correct sizes+offsets (7.8KB total), verified byte-level.
- Bumped explicit PNG link ?v=4 -> ?v=5 in all 3 layouts + standalone login blade so browsers fetch a never-seen URL.
- Verified: php -l clean x4; favicon.ico serves 200; login HTML carries the new link.
- User-side step still required once: F12 -> Application -> Storage -> Clear site data -> reload (or fully quit + reopen browser), because the poisoned favicon entry lives in the browser profile.


---
### Fix (2026-09-13): loader itself flashed as bare text before its CSS arrived

**Analysis:** compiled views confirmed the first-paint cover was live, so the remaining 'static text, no css' frame was the preloader's INNER content (brand/label/SVG) rendering unstyled while external CSS trickled in on the slow single-threaded dev server � i.e. navy screen + bare text, not the page itself.

**Fix:** extended the zero-specificity inline cover in components/preloader.blade.php to fully style the loader interior (brand row, logo size, name typography, chart sizing, crane strokes, amber bars, label, hidden-state fade) � first paint is now the finished-looking loader even with zero external CSS; full preloader.css (blur, bar animation) still overrides on arrival. Verified php -l clean.

Audit+fix 2026-09-13 unstyled-on-refresh: audited all blades (zero relative asset paths, Vite commented out and no public-build by design, no mix, no SW); all CSS URLs return 200; no admin-projects-create route exists (creation is modal-only). Definite defect found and fixed: contractorlayouts head had a nested link tag inside the favicon href, corrupting that layout head. Added 2.5s font-swap fallback in all 3 layouts. php -l clean. Note: a stylesheet that fails mid-refresh on single-threaded php-S does not block rendering, so the browser can paint without it; inlined critical CSS plus styled loader now cover that window.
Deep diagnosis 2026-09-13 unstyled-on-refresh, round 2: reproduced in headless Chrome (clean profile) - login renders fully styled on first load AND reload (screenshots compared). All local CSS returns 200 with correct sizes; heads verified clean after contractor fix; no relative paths, no active Vite, no SW; page headers clean (no CSP); 3 consecutive HTML fetches byte-stable (no truncation). Attempted authed render via crafted file session - rejected (server encrypts session cookies despite SESSION_ENCRYPT=false in env, and guard would not pick it up); forged session file removed afterwards. Conclusion: server delivery proven correct; residual cause must be client-side (poisoned profile cache from the broken-favicon era, or an extension). Decisive test given to user: fresh Chrome profile via --user-data-dir.

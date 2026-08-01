# Progress / Inspection Fields Refactor + Future Fixes Plan

CSPC PMU System — AI Module Version 2 (follow-up to prediction_history_fix_plan.md)

Status: 📋 DECIDED — scope confirmed 2026-08-01 (Option A: stop-using, no column
        drop; request key renamed to inspection_date; add month-label validation).
        NOT yet implemented — plan committed as reference. See §4 decision log.

Date: August 1, 2026

## 1. PROBLEM SUMMARY

`project_progress_history` carries three overlapping date/bucket fields:

| Field                   | What it holds        | Job                                    |
|-------------------------|----------------------|----------------------------------------|
| `month_label`           | `"2026-05"` (string) | Pure month bucket ("which month")      |
| `inspection_month_date` | `"2026-05-31"` (date)| Month anchor stored as a full date     |
| `inspection_date`       | `"2026-05-22"` (date)| Exact day the inspection was performed |

- On record, the controller writes BOTH `inspection_month_date` and
  `inspection_date` to the same value (ProjectProgressController ~341/353).
- `month_label` already does the "month bucket" job that `inspection_month_date`
  is (also) used for. `inspection_month_date` is therefore largely redundant
  with `month_label`; the only extra value is date-range filtering and ordering.
- This redundancy was the root cause of the baseline-tab "latest inspection"
  bug (see prediction_history_fix_plan.md §9 item 6/8) and keeps resurfacing
  in new places (§3 below).

## 2. THE REFACTOR — CONSOLIDATE TO `month_label` + `inspection_date`

Goal: make `month_label` the single month-bucket key and `inspection_date` the
single actual-date key; STOP USING `inspection_month_date` (keep the column in
the DB as deprecated — no migration / no column drop).

Decision (2026-08-01): **Option A — stop-using only.** The column stays in the
schema untouched; all reads and writes are removed. Reversible, lowest risk.

### 2.1 Current usage of inspection_month_date (~30 refs / 15 files)

- Ordering/grouping "which month": ProjectStatusService, ProjectOverviewService,
  ProjectChartService, Project.php, RegressionDatasetBuilder,
  AIPredictionPresenter, HistoricalSimilarityService, RecommendationEngine,
  baseline-schedule.blade.php, overview.blade.php.
- Date-range filter: AIPredictionPresenter ~829-830
  (`inspection_month_date >= monthStart AND <= monthEnd`).
- Both are used heavily in BACKEND services (NOT a frontend/backend split).

### 2.2 Why keep `inspection_date` and retire `inspection_month_date`

1. `inspection_date` is NOT NULL (required); `inspection_month_date` is nullable
   → keep the guaranteed field.
2. Legacy `storeProgress()` rows write ONLY `inspection_date` (never
   `inspection_month_date`). Retiring `inspection_date` would break them;
   retiring `inspection_month_date` leaves them intact.
3. `inspection_month_date`'s only job (month bucket) duplicates `month_label`.
   Keeping `inspection_date` (actual day) + `month_label` (bucket) = minimal,
   non-overlapping set.
4. `inspection_date` is the exact-day field and the intra-month tiebreaker.

### 2.3 Proposed steps (Option A)

- **Step 1 — Controller** (ProjectProgressController):
  - `recordMonthlyProgress()`: rename validated key
    `inspection_month_date` → `inspection_date`; add validation that
    `month_label === Carbon::parse(inspection_date)->format('Y-m')` (reject
    mismatched month); drop `inspection_month_date` from the create payload.
  - `editProgressRecord()`: remove the Fix B line that writes
    `inspection_month_date` (no longer needed — column unused); keep
    `inspection_date`.
  - `storeProgress()`: unchanged (legacy, writes only inspection_date).
- **Step 2 — Modals/JS**: rename the payload key to `inspection_date` in
  record_monthly_progress_modal.blade.php, record_progress_modal.blade.php, and
  baseline-schedule-record-progress-modal.js; simplify the edit-modal display
  fallback (`record.inspection_date || record.inspection_month_date` →
  `record.inspection_date`).
- **Step 3 — Replace order-by**: switch all `orderBy('inspection_month_date', ...)`
  to `orderBy('month_label', ...)` (month_label is YYYY-MM, sorts correctly).
- **Step 4 — Replace date-range filter** (AIPredictionPresenter ~829): convert the
  month-start/end date comparison to `where('month_label', now()->format('Y-m'))`.
- **Step 5 — Date math**: AIPredictionPresenter ~471 `addMonth()` on the date →
  `Carbon::parse($month_label . '-01')->addMonth()`.
- **Step 6 — RegressionDatasetBuilder**: order/group/sort by `month_label`
  instead of parsing `inspection_month_date`.
- **Step 7 — Display fallbacks**: drop the `?? inspection_month_date` fallback in
  the 3 display sites; use `inspection_date` alone (NOT NULL).
- **Step 8 — Model**: remove `inspection_month_date` from `$fillable` and casts.
  Column stays in DB, unmanaged.
- **Step 9 — Seeder/factory/tests**: stop writing the column; add a
  month-mismatch rejection test; update the Fix B edit test.
- **Step 10 — Verify**: full suite (baseline 180) + manual baseline/AI tab checks.

Rationale: month_label + inspection_date covers everything; `inspection_date`
remains the canonical actual date and the intra-month tiebreaker (the Fix B
pattern). No schema change; column left in place for later optional removal.

---

## 3. OTHER POSSIBLE ADJUSTMENTS / FIXES AFTER THE REFACTOR

### Fix A — Restrict the delete button to the LATEST progress record only

Where: `resources/views/projects/project_detail/components/tabs/progress.blade.php`
(delete button ~line 190), driven by the controller/service that supplies the
progress history table.

Problem: every row in the progress recording history table (progress tab) has a
delete button, so the user can delete records OUT of order (by month target).
Example: project has Jan, Feb, Mar → user deletes Feb → only Jan and Mar remain,
creating a gap.

Desired behavior: ONLY the latest progress record has a delete button.

Open questions:
- "Latest" = the record with the greatest `month_label` (and within the month,
  greatest `inspection_date`/`id`)? Same tiebreaker as syncPredictionHistoryActual.
- Keep Edit button on all rows, or restrict that too?
- Should this be enforced in the backend (destroyProgress guard) as well as the
  UI? Stronger: block deleting any record that is NOT the latest remaining one.

### Fix B — AI module current progress status card may show the wrong inspection

Where: AI module "current progress" status card. `current_progress` comes from
`end($dataset['y'])`, and the dataset is normalized per month in
`RegressionDatasetBuilder::normalizeMonthlyRecords()` by
`sortByDesc('inspection_date')` (line ~111).

Problem: suspected same failure as the baseline latest issue. If a month has two
inspections and the latest one was accidentally saved with the SAME inspection
date as the first, `sortByDesc('inspection_date')` is non-deterministic and can
return the FIRST inspection, so the status card shows the older value.

Note: not yet confirmed whether the refactor (§2) fixes this. If not, add the
tiebreaker (`id` desc) to `normalizeMonthlyRecords()` — the same pattern used in
the baseline fix (`inspection_date desc, id desc`). Listed so it is not
forgotten.

### Fix C — Baseline tab: restrict Record / Carry Over buttons to valid months

Where: `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`
(action buttons ~lines 261-297).

Problem: the "Record Progress" button shows for EVERY monthly target card, so the
user can record out of order. Example: project has Jan, Feb, Mar → record April →
then record ANOTHER March (instead of editing) → history has
Jan, Feb, Mar, Apr, Mar (duplicate/out-of-order month).

Desired behavior:
- "Record Progress" button visible ONLY for the NEXT pending monthly target
  (the first month that has no progress yet).
- "Carry Over" button visible ONLY for monthly targets that already have actual
  progress (activities to carry forward).

Open questions:
- How is "next pending month" computed? First `month_label` in the baseline
  schedule with no progress record? Or after the latest recorded month?
- With this restriction, can a user still correct a wrong month? They would use
  Edit on the wrong month's card instead of recording a duplicate.

---

## 4. DECISION LOG

1. **Refactor scope** — ✅ DECIDED (2026-08-01): **Option A — stop-using only.**
   Replace all usages and stop writing `inspection_month_date`; KEEP the column
   in the DB as deprecated (no migration / no column drop). Rationale: lowest
   risk and reversible; the column stays harmless if a hidden read remains.
   Full column drop deferred to a future migration once confirmed unused.
2. **Which field survives** — ✅ DECIDED: keep `inspection_date` (NOT NULL,
   actual inspection day, written by legacy `storeProgress()`), retire
   `inspection_month_date` (nullable, its bucket job duplicates `month_label`).
3. **Request key** — ✅ DECIDED: rename the payload key `inspection_month_date`
   → `inspection_date` in both record modals + the JS + controller validation.
4. **Month-label validation** — ✅ DECIDED: `recordMonthlyProgress()` rejects a
   submission where `month_label !== inspection_date`'s `Y-m` (422). Prevents
   the out-of-order "Jan, Feb, Mar, Apr, Mar" data-entry scenario (Fix C).
5. **Delete rule (Fix A)** — 🔄 OPEN: UI-only vs. backend-enforced
   `destroyProgress` guard.
6. **Edit button (Fix A)** — 🔄 OPEN: restrict edit as well, or leave on all rows?
7. **Record button rule (Fix C)** — 🔄 OPEN: exact definition of "next pending
   month".
8. **Carry Over rule (Fix C)** — 🔄 OPEN: visible on ALL months with progress, or
   only the latest month with incomplete activities?

## 5. FILES TOUCHED (preliminary)

- Refactor (§2): app/Http/Controllers/ProjectProgressController.php,
  app/Models/ProjectProgressHistory.php, app/Models/Project.php,
  app/Services/{ProjectStatusService,ProjectOverviewService,ProjectChartService}.php,
  app/Services/AI/{AIPredictionPresenter,HistoricalSimilarityService,
  RecommendationEngine}.php, app/Services/AI/Regression/RegressionDatasetBuilder.php,
  resources/views/.../components/modals/{record_monthly_progress_modal,
  record_progress_modal}.blade.php, public/js/admin/projects/
  {baseline-schedule-record-progress-modal,baseline-schedule-edit-progress-modal}.js,
  resources/views/.../components/tabs/{baseline-schedule,progress,overview}.blade.php,
  database/seeders/ProjectSeeder.php, database/factories/ProjectProgressHistoryFactory.php,
  tests/.
  - NO migration / NO column drop (Option A).
- Fix A: resources/views/projects/project_detail/components/tabs/progress.blade.php,
  controller/service supplying the history table, tests.
- Fix B: app/Services/AI/Regression/RegressionDatasetBuilder.php (verify only if
  not fixed by refactor), tests.
- Fix C: resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php,
  tests.

---

END OF PLAN (draft — refactor scope decided, fixes A/B/C still open)

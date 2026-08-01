# Progress / Inspection Fields Refactor + Future Fixes Plan

CSPC PMU System — AI Module Version 2 (follow-up to prediction_history_fix_plan.md)

Status: 🔄 DRAFT — future work, NOT yet implemented. Listed for reference so it is
        not forgotten. No changes made. See §4 decision log for open questions.

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
single actual-date key; drop `inspection_month_date` from the model over time.

### 2.1 Current usage of inspection_month_date (~44 refs / 15 files)

- Ordering/grouping "which month": ProjectStatusService, ProjectOverviewService,
  ProjectChartService, Project.php, RegressionDatasetBuilder,
  AIPredictionPresenter, HistoricalSimilarityService, RecommendationEngine.
- Date-range filter: AIPredictionPresenter ~829-830
  (`inspection_month_date >= monthStart AND <= monthEnd`).
- Both are used heavily in BACKEND services (NOT a frontend/backend split).

### 2.2 Why NOT drop inspection_date (why consolidation is month_label + inspection_date)

1. Legacy `storeProgress()` rows write ONLY `inspection_date` (never
   `inspection_month_date`) → `inspection_month_date` is nullable / NULL there.
2. Seeder deliberately differentiates them (month_date = 1st, inspection_date =
   end of month) — not "same by design".
3. `inspection_date` is the exact-day field and the intra-month tiebreaker.

### 2.3 Proposed steps

- **Step 1 — Backfill**: set `inspection_month_date` = coalesce(inspection_date,
  inspection_month_date) in any legacy rows where it is NULL (one-time data fix),
  so nothing breaks mid-migration.
- **Step 2 — Replace order-by**: switch all `orderBy('inspection_month_date', ...)`
  to `orderBy('month_label', ...)` (month_label is YYYY-MM, sorts correctly).
- **Step 3 — Replace date-range filter** (AIPredictionPresenter ~829): convert the
  month-start/end date comparison to a `month_label` string range.
- **Step 4 — Model/schema**: stop reading `inspection_month_date`; later drop the
  column in a migration once confirmed unused.
- **Step 5 — Seeder/factory/tests**: align to month_label + inspection_date only.
- **Step 6 — Verify**: full suite + manual baseline/AI tab checks.

Rationale: month_label + inspection_date covers everything; this is the ideal
end-state. Keep `inspection_date` as the canonical actual date and the
intra-month tiebreaker (already the pattern in Fix B).

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

## 4. DECISION LOG (open questions)

1. **Refactor scope** — full consolidation (§2) is a wide change (~44 refs / 15
   files + data backfill). Confirm it is worth doing now vs. keeping Fix B
   (both fields kept in sync) as the long-term state.
2. **Delete rule (Fix A)** — UI-only vs. backend-enforced `destroyProgress` guard.
3. **Edit button (Fix A)** — restrict edit as well, or leave on all rows?
4. **Record button rule (Fix C)** — exact definition of "next pending month".
5. **Carry Over rule (Fix C)** — visible on ALL months with progress, or only the
   latest month with incomplete activities?

## 5. FILES TOUCHED (preliminary)

- Refactor (§2): app/Services/{ProjectStatusService,ProjectOverviewService,
  ProjectChartService}.php, app/Models/Project.php,
  app/Services/AI/{AIPredictionPresenter,HistoricalSimilarityService,
  RecommendationEngine}.php, app/Services/AI/Regression/RegressionDatasetBuilder.php,
  database/migrations (backfill + optional column drop), database/seeders,
  database/factories, tests/.
- Fix A: resources/views/projects/project_detail/components/tabs/progress.blade.php,
  controller/service supplying the history table, tests.
- Fix B: app/Services/AI/Regression/RegressionDatasetBuilder.php (verify only if
  not fixed by refactor), tests.
- Fix C: resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php,
  tests.

---

END OF PLAN (draft)

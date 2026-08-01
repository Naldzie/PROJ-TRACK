# Progress / Inspection Fields Refactor + Future Fixes Plan

CSPC PMU System — AI Module Version 2 (follow-up to prediction_history_fix_plan.md)

Status: ✅ REFACTOR IMPLEMENTED & VERIFIED — 2026-08-01 (Option A: stop-using, no
        column drop; request key renamed to inspection_date; month-label validation
        added). Full suite 181 passed / 626 assertions. Fixes A–H still open
        (Fix B confirmed still unfixed; Fix G = the last-month 1-month grace window
        that the new month-match guard could break; Fix H = prediction can drop
        below current progress). See §3, §4, §6.

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

Status (2026-08-01, after §7 implementation):

| Fix | Status | Where |
|-----|--------|-------|
| A — delete latest-only | ✅ DONE (UI + backend guard) | progress.blade.php, ProjectProgressController::destroyProgress |
| B — current-progress tiebreaker | ✅ DONE (`inspection_date desc, id desc`) | RegressionDatasetBuilder::normalizeMonthlyRecords |
| C — record/carry-over restrictions | ✅ DONE (manual verify) | baseline-schedule.blade.php |
| D — prediction-history cap + scroll | ✅ DONE | AIPredictionPresenter, ai-prediction.blade.php |
| E — prediction-history month order | ✅ DONE | AIPredictionPresenter |
| F — progress-history month order | ✅ DONE | progress.blade.php |
| G — last-month 1-month grace | ✅ DONE | ProjectProgressController::recordMonthlyProgress |
| H — non-monotonic polynomial | 🔄 OPEN (deferred, see §3 + v2.5 A2) | — |

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

Note: ✅ CONFIRMED 2026-08-01 — the refactor (§2) did NOT fix this. The "current
progress" status card can still show the older inspection when a month has two
inspections saved with the SAME inspection date (non-deterministic sort). Fix: add
the tiebreaker (`id` desc) to `normalizeMonthlyRecords()` — the same pattern used in
the baseline fix (`inspection_date desc, id desc`).

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

### Fix D — Prediction History table: remove the 6-month cap and make it vertically scrollable

Where:
- `app/Services/AI/AIPredictionPresenter.php` ~397-403 — `->take(6)` caps the
  history to the 6 most recent distinct months.
- `resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php`
  ~704 — the table wrapper only has `overflow-x: auto` (no vertical scroll).

Confirmed (2026-08-01): the Prediction History & Accuracy table IS limited to the
6 most recent months (`take(6)` in the presenter). Earlier predictions become
unreachable once a project has more than 6 monthly predictions — this is why rows
"disappear" as the project grows.

Desired behavior: show the full prediction history (or a larger cap) and wrap the
table in a vertically scrollable container (max-height ~320px, `overflow-y: auto`,
sticky `<thead>` — matching the progress-tab history table at
progress.blade.php:86/105) so earlier months remain visible and reachable.

Open questions:
- Remove the cap entirely vs. a generous fixed cap (e.g. 12/24)? Removing it has
  no logic impact — the summary stats already recompute over `$predictionHistory`
  (lines 428-435).

### Fix E — Prediction History table: enforce chronological month order

Where: `app/Services/AI/AIPredictionPresenter.php` ~397-426 (prediction_history build).

Observed (2026-08-01): a project's history showed the latest month (August) with a
"September — pending" row directly below it, while the other pending months appeared
at the very top — i.e. rows were not in clean chronological-by-month order. Believed
trigger: editing progress for September (or similar out-of-order writes/edits).

Root cause (current logic): `$recentMonths` = 6 most recent distinct months;
`$rawHistory` is ordered `month_label ASC, id DESC`; then `groupBy('month_label')`
→ `map()` → `values()`. There is NO explicit final sort, so any grouping/order
anomaly passes straight through to the view.

Desired behavior / guard: after building `$predictionHistory`, sort explicitly by
the raw month key — include `month_label` in each row and apply
`sortBy('month_label')->values()` — guaranteeing oldest → newest regardless of
query/insertion/editing order.

### Fix F — Progress Recording History table: chronological month guard

Where: `resources/views/projects/project_detail/components/tabs/progress.blade.php`
line 77 — `$progressHistoryRecords = $project->progressHistory->sortByDesc('created_at');`

Confirmed (2026-08-01): the progress-tab history table is ordered by recording time
(`created_at` desc), NOT by month — there is no month-chronological guard. No
out-of-order issue seen yet, but add the guard for consistency (and because the
delete/order assumptions in Fix A depend on it).

Desired behavior / guard: order rows chronologically by month — `sortBy('month_label')`
ascending, with `inspection_date` asc + `id` asc as intra-month tiebreakers — so the
table reads oldest → newest month regardless of when records were created or edited.
The existing vertical scroll + sticky header (lines 86/105) already handle long lists.

Open questions:
- Ascending (oldest first, matching the prediction table) vs. descending (keeps the
  current newest-first feel)?

### Fix G — Preserve the "last month target + 1 month grace" recording window

Where:
- Frontend (feature confirmed present, 2026-08-01):
  - `baseline-schedule.blade.php` lines 263/271 pass `$loop->last ? '1' : '0'`
    (isLastMonth) into the record/edit modals.
  - `baseline-schedule-record-progress-modal.js` lines 36-38 and
    `baseline-schedule-edit-progress-modal.js` lines 56-58 extend the
    inspection-date picker's max to the end of the month AFTER the target month.
- Backend conflict: `ProjectProgressController::recordMonthlyProgress()` line 305
  (added by the refactor, decision #4) returns 422 whenever `inspection_date`'s
  `Y-m !== month_label` — this BREAKS the grace scenario below.

Purpose (from the user): when the last monthly target is nearly complete and the
final actual progress is only reached a few days into the following month (e.g. 99%
in March, 100% in early April), and no extension is granted because the remaining
work is minimal, the user must still be able to record that final inspection for the
last target month — with an inspection date up to 1 month after the target month.

Desired behavior: the backend month-match guard must make an exception for the LAST
monthly target — allow `inspection_date` up to 1 month after its `month_label`
(mirroring the frontend isLastMonth window) — while keeping the strict month-match
guard for all earlier months.

Open questions:
- Compute "last month" as the max `month_label` in the project's
  `ProjectBaselineSchedule` (same source as `$loop->last`).
- Should the grace also apply to editing the last month's record
  (`editProgressRecord`)? The frontend edit modal already allows it (isLastMonth).
- The record itself is keyed by `month_label`, so this is purely a validation-window
  change — ordering/sync/display are unaffected.

### Fix H — Next-month prediction can be LOWER than current actual progress (non-monotonic polynomial)

Where:
- `app/Services/AI/AIPredictionService.php` — `predictNextMonth()` (~540-565)
  clamps only to `max(0, min(100, $predicted))`, NOT to ≥ current progress.
- `app/Services/AI/ForecastService.php` (~155-161) — same clamp pattern; forecast
  months can decrease month-over-month.
- `app/Services/AI/CompletionEstimationService.php` — `estimatePolynomial()` (113)
  can solve the downward curve to 100% at a NEGATIVE index → past completion date.

Observed (2026-08-01, project 142 — "Construction of Barangay Evacuation Center"):
- Last baseline month = March 2027 (planned 100%), actual = 92%, extension_count = 0.
- Dataset: x=[1..12], y=[2,4,8,15,24,35,49,63,77,86,89,92] (May has 2 records → latest).
- SLR: `y = 9.5874x − 16.9848`, R²=0.9716 → next (x=13) = 107.65 (→ clamped 100).
- Poly (SELECTED — R² improvement 0.0267 > 0.02 threshold):
  `y = −0.1699x³ + 3.4535x² − 10.1622x + 10.4646`, R²=0.9983 → next (x=13) = 88.72.
- Result: predicted next month 88.72 < current actual 92 — cumulative progress
  "decreases", contradicting the app's own invariant (the controller rejects any
  recorded actual below the max recorded; Step 0 of recordMonthlyProgress).
- Collateral damage: `completion_multi.polynomial` estimated completion at
  index −3.6064 → April 2026 (a PAST date), causing a 408-day spread and
  agreement "Low" across the three methods.

Root cause: the cubic polynomial fit (degree 3, least squares) can be
non-monotonic BEYOND the observed range. Here the negative leading coefficient
(a = −0.1699) fits the recent slowdown (77→86→89→92) so tightly that the curve
peaks near the last point and turns downward past x=12. The prediction clamp
ignores the non-decreasing nature of cumulative progress.

Desired behavior:
1. `predictNextMonth()`: clamp to `max(floor, min(100, $predicted))` where the
   floor = current/latest actual progress — the next-month prediction must never
   drop below what is already achieved.
2. `ForecastService`: enforce a non-decreasing forecast month-over-month.
3. `CompletionEstimationService::estimatePolynomial()`: if the polynomial never
   reaches 100% monotonically after the current index (or solves at a negative /
   past index), fall back to the schedule-aware/SLR estimate or return null —
   never emit a past completion date.

Open questions:
- Floor for the clamp: latest actual (92) vs. max recorded actual across all
  history (safe floor — mirrors the controller's Step 0 guard)?
- When polynomial is selected but its extrapolation is decreasing, should we fall
  back to SLR for the next prediction instead of clamping (clamping alone can
  flatten the forecast to a plateau)?
- Should a non-monotonic tail disqualify polynomial from being "selected" (R²
  comparison) in the first place?

Cross-project tie-in: Option A2 of
`textfiles/regression_v2_5_cross_project_expansion_plan.md` is the
cross-project flavored version of item 3 above (fall back to the aggregate
category curve when the own-model completion is implausible). Implement A2
together with Fix H so the two paths don't conflict.

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
   ⚠️ Caveat (2026-08-01): must NOT apply to the LAST monthly target — the
   frontend intentionally allows its inspection_date up to 1 month after the
   target month (Fix G). Without an exception this guard breaks that grace window.
5. **Delete rule (Fix A)** — ✅ DECIDED (2026-08-01): **UI + backend guard.**
   Delete button rendered only on the latest remaining record (`$loop->first`
   after the newest-first sort) AND `destroyProgress()` rejects any deletion that
   is NOT the latest remaining record (422). Prevents out-of-order deletion.
6. **Edit button (Fix A)** — ✅ DECIDED: **leave Edit on all rows** (Edit is the
   sanctioned out-of-order-correction path; Delete is the destructive one).
7. **Record button rule (Fix C)** — ✅ DECIDED (2026-08-01, REVISED per user
   feedback): `Record Progress` shows on exactly TWO monthly targets — the
   CURRENT/latest month that has progress (so the user can add another
   inspection report; multiple inspections per month are supported) and the
   NEXT PENDING month (the first baseline month, `planned_date` asc, with no
   progress yet). All other months use Edit instead. Original one-month-only
   rule was corrected because it blocked adding a second inspection to the
   current month.
8. **Carry Over rule (Fix C)** — ✅ DECIDED: Carry Over shows only on months that
   ALREADY have actual progress (`$latestProgress` exists), in addition to the
   existing `$incompleteCount > 0 && $hasFutureMonths` condition.
9. **Progress-history order (Fix F)** — ✅ DECIDED (2026-08-01): **descending
   (newest first)** but ordered by `month_label` (then `inspection_date`, then
   `id`), not by `created_at`. Keeps the current newest-first feel while fixing
   the month ordering.
10. **Prediction-history cap (Fix D)** — ✅ DECIDED: **remove the `take(6)` cap
    entirely** — full history shown, scrollable. Summary stats (accuracy %,
    avg variance) then span ALL months with actuals (intended).

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
- Fix D: app/Services/AI/AIPredictionPresenter.php (take(6) cap),
  resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php
  (scrollable wrapper), tests.
- Fix E: app/Services/AI/AIPredictionPresenter.php (explicit month sort), tests.
- Fix F: resources/views/projects/project_detail/components/tabs/progress.blade.php
  (sort by month_label), tests.
- Fix G: app/Http/Controllers/ProjectProgressController.php (last-month grace
  exception to the month-match guard), tests.
- Fix H: app/Services/AI/AIPredictionService.php (non-decreasing clamp),
  app/Services/AI/ForecastService.php (non-decreasing forecast),
  app/Services/AI/CompletionEstimationService.php (polynomial fallback), tests.

---

## 6. CHANGES LOG — REFACTOR IMPLEMENTED (2026-08-01)

Branch: `refactor/inspection-date-consolidation` (from main). Commit pending.

### Step 1 — Controller (ProjectProgressController)
- `recordMonthlyProgress()`: validation key `inspection_month_date` →
  `inspection_date`; added guard returning 422 JSON when
  `Carbon::parse($validated['inspection_date'])->format('Y-m') !== month_label`;
  create payload uses `inspection_date` only (no inspection_month_date write).
- `editProgressRecord()`: removed the Fix B line that wrote `inspection_month_date`.
- `syncPredictionHistoryActual()`: `orderBy('inspection_month_date desc')` →
  `orderBy('inspection_date desc')` + `orderByDesc('id')`.

### Step 2 — Modals/JS
- `record_monthly_progress_modal.blade.php`: hidden input name + body key →
  `inspection_date`.
- `record_progress_modal.blade.php`: date input name → `inspection_date`.
- `baseline-schedule-record-progress-modal.js`: body key → `inspection_date`.
- `baseline-schedule-edit-progress-modal.js`: display fallback simplified to
  `record.inspection_date`.

### Step 3 — Order-by → month_label (12 sites)
- `ProjectChartService.php` (30, 90, 613, 660; sorts/fallbacks 142/176/566),
  `ProjectStatusService.php` (35, 75), `ProjectOverviewService.php` (25, 34),
  `Project.php` (149), `RecommendationEngine.php` (839),
  `HistoricalSimilarityService.php` (182, 303), `baseline-schedule.blade.php`
  (134), `overview.blade.php` (8), `AIPredictionPresenter.php` (466, 870, 938,
  1087).

### Step 4 — Date-range filter → month_label equality
- `AIPredictionPresenter.php` ~829-830: month-start/end range →
  `where('month_label', DateHelper::now()->format('Y-m'))`.

### Step 5 — Date math via month_label
- `AIPredictionPresenter.php` ~471: `addMonth()` on a date →
  `Carbon::parse($month_label . '-01')->addMonth()`.

### Step 6 — RegressionDatasetBuilder
- Order/group/sort by `month_label` (line 20, 104, 115).
- `first_month`/`last_month` statistics now use `inspection_date` (85/87).

### Step 7 — Display fallbacks
- Dropped `?? inspection_month_date` in `progress.blade.php` (139),
  `ProjectOverviewService.php` (34), `baseline-schedule.blade.php` (179).

### Step 8 — Model
- `ProjectProgressHistory.php`: `inspection_month_date` removed from
  `$fillable` and casts (column stays in DB, unmanaged).

### Step 9 — Seeder/factory/tests
- `ProjectSeeder.php` (363) and `ProjectProgressHistoryFactory.php`
  (definition, `forMonth()`, `sameMonth()`) stop writing the column.
- `CreatesProjectWithHistory.php`: all 10 `inspection_month_date` writes removed.
- `PredictionHistorySyncTest.php`: helper key → `inspection_date`; the Fix B edit
  test → `test_edit_updates_inspection_date`; added
  `test_record_rejects_inspection_date_mismatching_month` (422).
- `RegressionDatasetBuilderTest.php`, `AIPredictionPresenterTest.php`,
  `AIPredictionServiceTest.php`: order assertions / writes updated.

### Step 10 — Verify
- Full suite: **181 passed / 626 assertions** (baseline 180/623).
- Repo-wide grep: no remaining `inspection_month_date` reads/writes in app/,
  tests/, resources/, public/ (only comments, `merging/` untracked copy, docs,
  and schema migrations remain — by design).

### Remaining (unchanged)
- Fix A (delete latest-only), Fix B (normalize tiebreaker `id` desc — CONFIRMED
  still unfixed 2026-08-01), Fix C (record/carry-over button restrictions), plus
  new Fix D (history cap + vertical scroll), Fix E (prediction-table order guard),
  Fix F (progress-tab order guard), Fix G (last-month 1-month grace vs. the new
  month-match guard), Fix H (next-month prediction can drop below current
  progress; also breaks completion estimation).
- ✅ 2026-08-01: Fixes **A–G** IMPLEMENTED — see the log below (branch
  `fix/progress-additional-fixes`). Only **Fix H** remains fully OPEN (deferred;
  see §3 + the v2.5 plan Option A2).

### ADDITIONAL FIXES A–G IMPLEMENTED (2026-08-01)

Branch: `fix/progress-additional-fixes` (from main `9d32392`). Five bundles,
committed incrementally; full suite green after each. Final suite: **195 passed /
669 assertions** (baseline for this branch 186/641).

1. **Fix B** (`9576dbf`) — `RegressionDatasetBuilder::normalizeMonthlyRecords()`:
   `sortByDesc('inspection_date')` → multi-key `sortByDesc(['inspection_date',
   'id'])` (chained sortByDesc is stable, so it had to be a single multi-key
   sort; string keys — closures break Laravel's `sortByMany`, and Carbon values
   compare fine under SORT_REGULAR). Test:
   `test_build_dataset_picks_latest_id_when_same_month_and_same_inspection_date`.
2. **Fix F + A** (`81e72c6`) —
   - `progress.blade.php`: history sort `sortByDesc('created_at')` →
     newest-first by `month_label|inspection_date|id` composite; admin Delete
     button only on the latest row (`$loop->first`).
   - `ProjectProgressController::destroyProgress()`: rejects any deletion that is
     NOT the latest remaining record (month_label desc, COALESCE inspection_date
     desc, id desc) — 422.
   - New `tests/Feature/Services/AI/ProgressDeleteGuardTest.php` (3 tests); the
     four `PredictionHistorySyncTest` delete scenarios updated to delete
     latest-first (they previously deleted out of order, which the new guard
     correctly blocks).
3. **Fix D + E** (`d6b9db2`) — `AIPredictionPresenter.php`: removed the
   `take(6)` cap and the `$recentMonths`/`$rawHistory` split (history now covers
   every distinct month), each row carries `month_label`, and the result is
   explicitly `sortBy('month_label')` (oldest → newest). `ai-prediction.blade.php`:
   history table gets `max-height:320px; overflow-y:auto` + sticky `<thead>`.
   Tests: `test_prediction_history_is_not_capped_at_six`,
   `test_prediction_history_is_sorted_by_month_ascending`.
4. **Fix G** (`386c304`) — `recordMonthlyProgress()` month-match guard now allows
   the LAST target month (`baselineSchedules()->max('month_label')`) an
   inspection date up to one month AFTER its month (mirrors the frontend
   `isLastMonth` window); all earlier months keep the strict match. Tests:
   `test_record_allows_last_month_inspection_one_month_after`,
   `test_record_rejects_last_month_inspection_beyond_one_month_grace`,
   `test_record_rejects_non_last_month_inspection_outside_its_month`. Note: the
   edit path has no backend month-match guard, so the grace already applies there.
5. **Fix C** (`60a16c7`, corrected 2026-08-01) — `baseline-schedule.blade.php`:
   Record Progress renders on exactly TWO monthly targets — the CURRENT/latest
   month with progress (`month_label === max` of recorded progress months) and
   the NEXT PENDING month (first baseline month with no progress), precomputed
   before the loop; Carry Over additionally requires `$latestProgress`.
   Edit/Manage/Delete unchanged. Manual verification pending (blade-only).
   `php artisan view:cache` passes. (First version showed Record only on the
   single next-pending month — corrected because that prevented a second
   inspection report on the current month.)

---

## 7. IMPLEMENTATION PLAN — ADDITIONAL FIXES A–G (2026-08-01)

> ✅ IMPLEMENTED — see §6 "ADDITIONAL FIXES A–G IMPLEMENTED" log and §3 status
> table. This section is retained as the record of the approved plan.

Branch: `fix/progress-additional-fixes` (from `refactor/inspection-date-consolidation`,
which == `main` at `9d32392`). Five self-contained bundles; full suite run
after each; merge to `main` on approval. Full suite baseline: 186 passed / 641
assertions.

### Bundle 1 — Fix B: current-progress status-card tiebreaker
- `app/Services/AI/Regression/RegressionDatasetBuilder.php` `normalizeMonthlyRecords()`
  (~109): `->sortByDesc('inspection_date')->first()` → real multi-key sort
  `->sortByDesc([fn ($r) => $r->inspection_date, fn ($r) => $r->id])->first()`
  (chaining two `sortByDesc` is stable so the second would override the first).
  Picks the highest-id inspection when two share the same inspection date.
- Test: public `buildDataset()` — two records same month + same `inspection_date`,
  different `actual_progress_percent` → last `y` = the higher-id record.

### Bundle 2 — Fix F + Fix A: progress-history order + latest-only delete
- `resources/views/.../tabs/progress.blade.php` (77): replace
  `sortByDesc('created_at')` with month-first newest-first, null-safe:
  `sortByDesc(fn($h) => $h->month_label.'|'.($h->inspection_date ?? '0000-00-00').'|'.str_pad($h->id, 12, '0', STR_PAD_LEFT))`.
- `progress.blade.php` (189-195): admin Delete button only on the latest row
  (`@if(auth()->user()->role === 'admin' && $loop->first)`). Edit stays on all rows.
- `app/Http/Controllers/ProjectProgressController.php` `destroyProgress()` (after ~222):
  reject deletion of any record that is NOT the latest remaining — latest =
  `orderByDesc('month_label')`, `orderByRaw('COALESCE(inspection_date,"0000-00-00") DESC')`,
  `orderByDesc('id')` first row; else 422.
- Tests: Jan/Feb/Mar → delete Mar 200; delete Feb 422; delete sole record 200.

### Bundle 3 — Fix D + Fix E: prediction history (cap + order + scroll)
- `app/Services/AI/AIPredictionPresenter.php` (402): remove `->take(6)` and the
  stale "Limited to 6" comment.
- `AIPredictionPresenter.php` (411-426): add `'month_label' => $latest->month_label`
  to each history row, then `->sortBy('month_label')->values()` (oldest → newest).
- `resources/views/.../tabs/ai-prediction.blade.php` (704-713): wrapper →
  `overflow-x:auto; overflow-y:auto; max-height:320px`; sticky `<thead>`
  (`position:sticky; top:0`), mirroring the progress-tab history table.
- Tests: 8 distinct months → history count = 8 (not 6); out-of-order inserts →
  output ascending by `month_label`.

### Bundle 4 — Fix G: last-month 1-month grace vs. the month-match guard
- `ProjectProgressController::recordMonthlyProgress()` (305-310): keep strict
  month-match for all months EXCEPT the last target month (`max('month_label')`
  in `ProjectBaselineSchedule`), which also accepts the month AFTER its
  `month_label` (mirrors the frontend `isLastMonth` window).
- Note: `editProgressRecord()` has no backend month-match guard today — grace
  already applies there via the frontend; leave as-is.
- Tests: last target (Jul) + date 2026-08-15 → 200; + 2026-09-01 → 422; non-last
  (Jun) + 2026-07-15 → 422.

### Bundle 5 — Fix C: baseline-tab button restrictions
- `resources/views/.../tabs/baseline-schedule.blade.php`: Record Progress is
  offered on exactly TWO monthly targets — the CURRENT/latest month with
  progress (to add another inspection report) and the NEXT PENDING month (the
  first `planned_date`-asc month with no progress). Precomputed before the
  loop (`$latestProgressMonth` = max recorded progress month,
  `$nextPendingMonth` = first unrecorded baseline month):
  - `@if($showRecordButton)` wraps the Record button (263), where
    `$showRecordButton = $plan->month_label === $latestProgressMonth ||
    $plan->month_label === $nextPendingMonth`.
  - Carry Over (279) additionally requires `$latestProgress`.
  - Edit / Manage / Delete unchanged.
- Verification: manual (blade-only button visibility).

### Docs
- §6 CHANGES LOG updated as each bundle lands (sub-steps + suite counts); §3
  fix statuses → DONE / MANUAL-VERIFIED; Fix H stays OPEN.

---

END OF PLAN (refactor implemented 2026-08-01; fixes A–G implemented §6 / Fix H open)

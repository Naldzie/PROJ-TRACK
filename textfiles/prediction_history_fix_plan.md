# Prediction History Auto-Save Fix Plan

CSPC PMU System — AI Module Version 2 (Phase 7 follow-up)

Status: ✅ COMPLETE — v1 fix implemented & verified (commits 238f8ee, 6911f13, 98e62e0); see CHANGES LOG (§9).
        🔄 REVISION v2 OPEN — Record all predictions (evolution history); see §10.

Date: July 31, 2026

## 1. PROBLEM STATEMENT

The `prediction_history` table (Phase 7) auto-saves one prediction per inspection month, and
`ProjectProgressController` backfills the `actual_next` value when new progress is recorded.

Two bugs were reproduced with the time-simulation feature on project 142:

### Bug A — Backfill writes the wrong actual (observed)

Scenario:

1. April 15 — progress set to 2%. AI predicts May = 4.72%. Row saved (month_label = 2026-05).
2. May 15 — progress set to 4%. AI predicts June = 6.13%. Row saved (month_label = 2026-06).
   The May row gets `actual_next = 4` (correct).
3. May 22 — a SECOND inspection for May records 4.5%.
4. The backfill grabs the LATEST row with `actual_next IS NULL` — which is the **June**
   prediction row — and writes May's 4.5% into it.

Result stored in DB:

| id | month_label | current | predicted | actual  | created    | status                        |
|----|-------------|---------|-----------|---------|------------|-------------------------------|
| 33 | 2026-05     | 2.00    | 4.72      | 4.00    | 04-15       | correct (May prediction)       |
| 34 | 2026-06     | 4.00    | 6.13      | **4.50** | 05-15       | WRONG — May actual in June row |
| 35 | 2026-06     | 4.50    | 6.55      | null    | 05-22       | duplicate June prediction      |

The frontend table then shows: `Jun 2026 | pred 6.13 | actual 4.5 | var -1.63` — misleading.

### Bug B — Dedupe is too weak (latent, present in data)

`AIPredictionService::savePredictionHistory()` dedupes by `current_progress`. Re-running the
model with the corrected 4.5% data (page re-view) produced a SECOND June prediction row (id 35).
It is hidden from the table only because the presenter filters to rows with `actual_next`.

### Root Causes

1. `ProjectProgressController::recordMonthlyProgress()` backfills by
   `latest('created_at') WHERE actual_next IS NULL` — it ignores which month the inspection
   belongs to (lines 349-365).
2. `AIPredictionService::savePredictionHistory()` dedupes by `current_progress` instead of
   the predicted month (lines 592-598).

## 2. CLARIFICATIONS (from discussion)

- The `month_label` stored in `prediction_history` is the **predicted month** (the month the
  prediction is FOR), derived from baseline `offset(currentMonthIndex)`. It is NOT the month
  the prediction was made. This is by design — not a bug.
- Adding `baseline_schedule_id` will store the **predicted month's** baseline id.
  Example: April inspection predicts May → stores the May baseline's id.
- Dedupe will check if a prediction already exists for that predicted month
  (by `baseline_schedule_id` and/or `month_label`) → skip (keep the FIRST prediction).
- On delete of a progress record: if no progress remains for that month, `actual_next`
  reverts to `null` ("actual removed"). If a different record remains, `actual_next` is
  recomputed from the latest remaining one.

## 3. CHOSEN DIRECTION

### A single "recompute from source of truth" sync helper

All three progress-record mutations (add / edit / delete) call one private helper that
recomputes `actual_next` for the affected month directly from `project_progress_history`:

- **add** (`recordMonthlyProgress`) → replaces the broken backfill; same-month re-inspection
  overrides (May 4.0 → 4.5).
- **edit** (`editProgressRecord`) → edited value flows into `actual_next`.
- **delete** (`destroyProgress`) → recompute from remaining records; `null` if none remain
  (handles wrong-month records being removed).

### Dedupe by predicted month, keep the first

One `prediction_history` row per (project, predicted month). The original prediction is kept
for honest accuracy tracking; `actual_next` is updated by the sync helper.

### Add `baseline_schedule_id` linkage

Prediction rows store the predicted month's baseline id for explicit traceability and as a
secondary match key. `month_label` remains the primary match key (guaranteed present on both
`prediction_history` and `project_progress_history` rows).

## 4. IMPLEMENTATION STEPS

### Step 1 — Migration: add baseline_schedule_id

File (NEW): `database/migrations/<timestamp>_add_baseline_schedule_id_to_prediction_history_table.php`

- Add nullable `baseline_schedule_id` FK → `project_baseline_schedules`
  (on delete set null), indexed.
- Backfill existing rows: set `baseline_schedule_id` = the baseline whose `month_label`
  matches each row's `month_label`.

File (MOD): `app/Models/PredictionHistory.php`

- Add `baseline_schedule_id` to `$fillable`.
- Add `baselineSchedule()` belongsTo relation.

### Step 2 — Fix dedupe in AIPredictionService

File (MOD): `app/Services/AI/AIPredictionService.php` — `savePredictionHistory()` (lines ~580-627)

- Move the exists-check to AFTER `$monthLabel` is computed.
- Check `where('project_id')->where('month_label', $monthLabel)` (plus `baseline_schedule_id`
  when available) → if a prediction for that month already exists, `return` (keep first).
- Store `baseline_schedule_id` on the new row (same offset lookup that yields `month_label`).

### Step 3 — Add sync helper in ProjectProgressController

File (MOD): `app/Http/Controllers/ProjectProgressController.php`

New private method:

```
syncPredictionHistoryActual(Project $project, string $monthLabel): void
```

Logic:

1. Latest remaining `project_progress_history` for `(project_id, month_label)`
   (`orderBy('inspection_date', 'desc')`).
2. Set `actual_next` on matching `prediction_history` rows to that record's
   `actual_progress_percent` — or `null` if no record remains.
3. Non-blocking (try/catch + `Log::warning`), matching the existing backfill pattern.

Wire it in:

| Endpoint                     | Change                                                                 |
|------------------------------|------------------------------------------------------------------------|
| `recordMonthlyProgress`       | Replace broken backfill (lines 349-365) with the helper call           |
| `editProgressRecord`          | After `DB::commit()` when `$isMonthlyRecord` && `month_label` → helper |
| `destroyProgress`             | After `DB::commit()` → helper (admin-only path)                        |

### Step 4 — Clean up existing bad data (one-time)

- Delete duplicate `prediction_history` rows per `(project_id, month_label)` keeping the
  earliest id → removes id 35.
- Recompute `actual_next` for remaining rows from the matching month's LATEST progress record:
  - id 34 → `null`
  - id 33 → `4.50`

### Step 5 — Tests & verification

DONE. Added:

- `tests/Unit/Services/AI/AIPredictionServiceTest.php` — `test_prediction_saves_predicted_month_label_and_baseline_id` (April 2% → May prediction linked to May baseline), `test_prediction_does_not_duplicate_on_repeated_generation`, `test_second_inspection_does_not_create_duplicate_next_month_prediction` (May 22 4.5% → no duplicate June row).
- `tests/Feature/Services/AI/PredictionHistorySyncTest.php` (NEW, controller-level) — `test_april_prediction_is_saved_for_may_with_baseline_link`, `test_same_month_re_inspection_updates_the_correct_actual` (May 15 4% → May actual 4.0 + June saved; May 22 4.5% → May actual 4.5, June stays null, no duplicate), `test_edit_and_delete_recompute_actual_next` (edit 4.5→5.0 updates; delete one of two → latest remaining; delete last → null; re-record → re-filled).
- `tests/Unit/Services/AI/AIPredictionPresenterTest.php` — `test_prediction_history_shows_correct_month_actual` (history lists May 4.5, not a bogus June row).

Also fixed the pre-existing test infra so the suite can run at all: platform-aware `2026_07_18_210523_*` and `2026_07_30_031232_*` migrations, and updated stale AI service constructor calls in 4 test files (commit 6911f13).

Result: full suite green — **173 tests passed (555 assertions)**.

Manual: re-run the time-simulation scenario; confirm the table shows only
`May (4.72 / 4.5)` until June is inspected; DB has one row per predicted month.

## 5. FILES TOUCHED

- NEW `database/migrations/<timestamp>_add_baseline_schedule_id_to_prediction_history_table.php`
- MOD `app/Models/PredictionHistory.php`
- MOD `app/Services/AI/AIPredictionService.php`
- MOD `app/Http/Controllers/ProjectProgressController.php`
- NEW/EXT tests under `tests/`

## 6. DECISION LOG

1. **Match key** — `month_label` is the primary match key for dedupe and the sync helper
   (guaranteed present on both tables). `baseline_schedule_id` is stored for traceability and
   as a secondary key. Rationale: robustness over purity; `month_label` always exists.
2. **Duplicate policy** — keep the FIRST prediction for a predicted month (skip new ones).
   Rationale: preserves the original prediction for honest accuracy tracking; `actual_next`
   is maintained separately by the sync helper.
3. **Delete behavior** — recompute `actual_next` from remaining records; `null` if none.
   Rationale: removing a wrong-month record removes its actual; the row reverts to "pending"
   and re-fills when a correct record is entered.
4. **Helper location** — private method in `ProjectProgressController` (all three call sites
   live there). May be extracted to a service later if other callers appear.

## 7. FLAGGED FOR FUTURE

- `editProgressRecord` does not allow changing `month_label` (month is fixed on edit) — a
  wrong-month record must be deleted and re-entered. Optionally allow month moves later.
- The legacy `storeProgress()` path (non-monthly) does not sync — considered dead code; leave
  as-is unless it is proven reachable.
- `AIExplanationService` accuracy stats consume `prediction_history` — will be corrected
  automatically once the bad rows are cleaned.

## 8. CONVERSATION CONTINUATION GUIDE

1. Confirm `textfiles/prediction_history_fix_plan.md` is the current plan.
2. Branch: `fix/prediction-history-month-linking` (from main).
3. Implement Steps 1-5 in order; test each step before proceeding (Rule 8).
4. After implementation and tests, inform for review before merging to main (Rule 12).

---
END OF PLAN (v1)

---

## 9. CHANGES LOG — V1 FIX IMPLEMENTED

(Format follows `textfiles/fixes_and_improvements_log.txt`.)

Logged: 2026-07-31

0. FIXED prediction_history backfill writing the wrong month's actual (Bug A)
----------------------------------------------------------------------
  Branch: fix/prediction-history-month-linking (from main)
  Files:
    app/Http/Controllers/ProjectProgressController.php
    app/Models/PredictionHistory.php

  Problem: recordMonthlyProgress() backfilled by
  latest('created_at') WHERE actual_next IS NULL. A second May inspection
  (4.5%) wrote May's actual into the JUNE prediction row (id 34),
  corrupting the accuracy display.

  Solution: Replaced the backfill with a single helper
  syncPredictionHistoryActual(project, monthLabel) that recomputes
  actual_next directly from project_progress_history for the CORRECT
  month, wired into add / edit / delete (commit 238f8ee).

1. FIXED prediction dedupe — now keyed by predicted month (Bug B)
----------------------------------------------------------------------
  Branch: fix/prediction-history-month-linking
  Files:
    app/Services/AI/AIPredictionService.php

  Problem: savePredictionHistory() deduped by current_progress only, so
  re-running the model with the corrected data produced a second June
  prediction row (id 35).

  Solution: Dedupe by the predicted month (month_label / baseline_schedule_id);
  keep the FIRST prediction per (project, predicted month) (commit 238f8ee).

2. ADDED baseline_schedule_id linkage to prediction rows
----------------------------------------------------------------------
  Branch: fix/prediction-history-month-linking
  Files:
    database/migrations/2026_07_31_000001_add_baseline_schedule_id_to_prediction_history_table.php (NEW)
    app/Models/PredictionHistory.php

  Problem: Prediction rows had no traceable link to the baseline month
  they predict.

  Solution: New nullable FK baseline_schedule_id → project_baseline_schedules
  (on delete set null, indexed); backfilled by matching month_label; stored
  on every new prediction row (commit 238f8ee).

3. FIXED test suite being un-runnable on sqlite
----------------------------------------------------------------------
  Branch: fix/prediction-history-month-linking
  Files:
    database/migrations/2026_07_18_210523_change_activity_id_to_json_in_progress_history.php
    database/migrations/2026_07_30_031232_add_unique_constraint_to_email_trackings.php
    tests/Unit/Services/AI/AIPredictionServiceTest.php
    tests/Unit/Services/AI/AIIntegrationTest.php
    tests/Unit/Services/AI/AIPredictionPresenterTest.php
    tests/Unit/Services/AI/ForecastServiceTest.php

  Problem: phpunit forces sqlite :memory: but two migrations used
  MySQL-only syntax (INFORMATION_SCHEMA, SHOW INDEX, ALTER TABLE MODIFY,
  JSON_* functions, DELETE ... INNER JOIN). The test suite could not run
  at all (34 failed / 132 passed).

  Solution: Made both migrations platform-aware (SQLite PRAGMA-based path
  for the JSON conversion; portable whereExists correlated subquery for the
  dedupe) and updated stale AI service constructor calls in 4 test files.
  Suite went to 166 passed (commit 6911f13).

4. ADDED Step 5 regression tests for the v1 fix
----------------------------------------------------------------------
  Branch: fix/prediction-history-month-linking
  Files:
    tests/Unit/Services/AI/AIPredictionServiceTest.php
    tests/Feature/Services/AI/PredictionHistorySyncTest.php (NEW)
    tests/Unit/Services/AI/AIPredictionPresenterTest.php

  Problem: No automated coverage for the month-linked backfill, month
  dedupe, or add/edit/delete actual_next recompute behavior.

  Solution: Added service-level dedupe/baseline-link tests, a controller-
  level PredictionHistorySyncTest (add/edit/delete flow through the real
  controller), and a presenter history test (commit 98e62e0). Full suite
  green: 173 tests / 555 assertions.

---

## 10. REVISION v2 PLAN — RECORD ALL PREDICTIONS (EVOLUTION HISTORY)

Status: 🔄 OPEN — decided 2026-07-31; not yet implemented.

### 10.1 Problem

Under v1, only the FIRST prediction per predicted month is recorded
(keep-first). When a month's data is updated, the AI's live prediction
changes but the recorded history does not — so what the user SAW and what
the history records diverge.

Example (confirmed with the real module):

1. First May inspection 4.0% → June predicted 5.13% (recorded).
2. Second May inspection 4.5% → AI now shows June ≈ 5.30% (live panel).
3. Under v1, only 5.13% is recorded. When June's actual arrives, accuracy
   compares against 5.13% — not the 5.30% the user last saw.

Result: the recorded history and the "next prediction" panel disagree, and
the accuracy/evolution story is incomplete.

### 10.2 Chosen Direction

1. **Record all distinct predictions.** `savePredictionHistory()` creates a
   new row whenever the forecast changes. Dedupe key:
   `(project_id, month_label, current_progress, predicted_next)`. Identical
   re-generation (page re-view / repeated call with same data) is skipped,
   so no refresh duplicates — while every genuinely different forecast is
   preserved.
2. **actual_next → latest row per month only.** When a month's actual is
   recorded, write it to the NEWEST prediction row for that month and
   explicitly null older rows (superseded). Accuracy stats stay
   one-per-month and reflect the forecast the user most recently relied on.
3. **Collapsed per-month display.** The history table shows ONE row per
   month. Multiple forecasts render as an evolution, e.g. `5.13 → 5.30`,
   with actual/variance shown for the final forecast; pending rows (no
   actual yet) show `—` and are dimmed.

No migration / schema change — existing rows stay valid (they are the first
forecasts of their months).

### 10.3 Implementation Steps

### Step 1 — Dedupe → create-on-change (AIPredictionService)

File (MOD): `app/Services/AI/AIPredictionService.php` — `savePredictionHistory()` (lines ~581-649)

- Replace the "first per predicted month" exists-check with:
  `where(project_id)->where(month_label, $monthLabel)->where(current_progress, round($currentProgress,2))->where(predicted_next, round($predictedNextProgress,2))`
  → skip only when an identical forecast already exists; otherwise CREATE a new row.
- Keep the `>= 100` guard, the predicted-month baseline resolution, and the
  no-month legacy fallback.
- Update the method docblock to describe the evolution semantics.

### Step 2 — actual_next on latest row only (ProjectProgressController)

File (MOD): `app/Http/Controllers/ProjectProgressController.php` — `syncPredictionHistoryActual()` (line ~434)

- Find the newest row for `(project_id, month_label)` via `orderByDesc('id')->first()`.
- Set `actual_next = $actual` on it; null any other rows for the month
  (self-heals rows that received actual under v1).
- If no row exists for the month, no-op (same non-blocking try/catch as today).

### Step 3 — Collapsed per-month history (AIPredictionPresenter)

File (MOD): `app/Services/AI/AIPredictionPresenter.php` — history build (lines ~394-415)

- Query the 6 most recent distinct `month_label`s, fetch their rows ordered by
  `month_label` asc, `id` asc, then group by `month_label` in PHP.
- Each collapsed entry: `month`, `predicted` (latest row), `predicted_evolution`
  (all values, ordered), `prediction_count`, `actual` (latest row's, else null),
  `variance` (or null), `method` (latest row), `pending` (true when no actual yet).
- Compute the summary (`total_with_actual`, `accurate_count`, `accuracy_percent`,
  `avg_variance`) from the collapsed months that HAVE an actual — same meaning
  as v1.

### Step 4 — Blade evolution display

File (MOD): `resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php` (lines ~716-733)

- Predicted cell: when `prediction_count > 1`, render `5.13 → 5.30` (final value
  bold); otherwise the single value.
- Actual / Variance cells: `—` when `pending`; keep the existing variance color
  logic for filled rows; dim pending rows.
- Summary stats row unchanged.

### Step 5 — AIExplanationService

No change expected: it already filters `whereNotNull('actual_next')`, which under
v2 yields exactly the latest (accuracy-relevant) row per month. Verify only.

### Step 6 — Tests

- MOD `tests/Unit/Services/AI/AIPredictionServiceTest.php`:
  rewrite `test_second_inspection_does_not_create_duplicate_next_month_prediction`
  → `test_second_inspection_records_evolved_prediction_for_next_month` (May 4% and
  May 4.5% now yield TWO June rows; identical same-data re-generation still adds none).
  Keep the other two history tests.
- MOD `tests/Feature/Services/AI/PredictionHistorySyncTest.php`:
  update `test_same_month_re_inspection_updates_the_correct_actual` (counts become
  3 after May 4.5% + generate); add `test_actual_next_is_written_only_to_latest_prediction_row`.
- MOD `tests/Unit/Services/AI/AIPredictionPresenterTest.php`:
  update `test_prediction_history_shows_correct_month_actual` for the collapsed
  format; add `test_history_collapses_multiple_predictions_per_month`.

### Step 7 — Verify

- `php artisan test --filter=AIPredictionServiceTest`
- `php artisan test --filter=PredictionHistorySyncTest`
- `php artisan test --filter=AIPredictionPresenterTest`
- Full suite `php artisan test` (baseline: 173 green).

### 10.4 Files Touched

- MOD `app/Services/AI/AIPredictionService.php`
- MOD `app/Http/Controllers/ProjectProgressController.php`
- MOD `app/Services/AI/AIPredictionPresenter.php`
- MOD `resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php`
- MOD `tests/Unit/Services/AI/AIPredictionServiceTest.php`
- MOD `tests/Feature/Services/AI/PredictionHistorySyncTest.php`
- MOD `tests/Unit/Services/AI/AIPredictionPresenterTest.php`
- (verify only) `app/Services/AI/AIExplanationService.php`
- No migration / schema change.

### 10.5 Decision Log (v2)

1. **Record-all vs keep-first / keep-latest** — record-all. Every forecast
   shown to the user is preserved and the recorded history always matches
   what the user saw. Rationale: transparency over minimalism.
2. **actual_next target** — latest row per month only. Keeps accuracy stats
   one-per-month and compares against the forecast the user most recently
   relied on. Superseded rows stay as history without an actual.
3. **Display** — collapsed per month with an evolution arrow. Compact table
   while still surfacing forecast changes.
4. **Dedupe key** — `(project_id, month_label, current_progress, predicted_next)`.
   Captures every distinct forecast, prevents page-refresh duplicates, and
   also catches forecast changes that occur without the latest observation
   changing (e.g., an earlier month edited).

---
END OF PLAN (rev 2)

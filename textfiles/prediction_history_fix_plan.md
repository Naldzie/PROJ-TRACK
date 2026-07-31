# Prediction History Auto-Save Fix Plan

CSPC PMU System — AI Module Version 2 (Phase 7 follow-up)

Status: ✅ COMPLETE — v1 fix implemented & verified (commits 238f8ee, 6911f13, 98e62e0); see CHANGES LOG (§9).
        ✅ REVISION v2 (record-all) implemented, then REVERTED after project 142 testing.
        🔄 REVISION v3 — Keep-latest prediction per month; see §10.

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

## 10. REVISION v3 PLAN — KEEP-LATEST PREDICTION (UPDATE IN PLACE)

Status: 🔄 IMPLEMENTED 2026-07-31 (commit pending). Rev 2 (record-all) was
        implemented, then REVERTED after real-world testing on project 142.

### 10.1 Problem

Under v1 only the FIRST prediction per predicted month was recorded, so the
recorded history diverged from the forecast the user last saw. Rev 2 fixed
that by recording EVERY distinct forecast (evolution history).

Real-world testing on project 142 exposed a problem with record-all:

- May 15 inspection 4.0% → June predicted 6.13% (id 39).
- A second inspection 4.5% was added but its inspection date was LEFT at
  May 15. The dataset's "latest observation" momentarily flip-flopped and a
  DIFFERENT prediction (6.05%, id 40) was saved — a spurious row caused by
  the date mistake.
- Editing the record's inspection date to May 22 then produced the correct
  6.55% forecast — as ANOTHER new row (id 41).

Result: the predicted month had 3 rows, one of which (id 40) was pure noise,
and fixing the mistake added yet more clutter. Record-all preserves mistakes
and rewards correcting them with more rows.

### 10.2 Chosen Direction — Keep-Latest

1. **One prediction row per (project, predicted month), refreshed in place.**
   `savePredictionHistory()` UPDATES the pending row whenever the forecast
   changes (new `current_progress` / `predicted_next`); identical
   re-generation (page re-view) is skipped; only a brand-new month gets a
   CREATE. This keeps the history equal to the forecast the user last saw —
   the ORIGINAL v1 complaint — without accumulating noise.
2. **Fulfilled-month guard (unchanged from rev 2).** Once a month has an
   actual, no create or update for it (no hindsight forecasts).
3. **actual_next → newest row only (unchanged from rev 2).** With one row per
   month this is trivially the single row; the "null superseded rows" step
   self-heals any pre-rev3 duplicate rows in existing data.
4. **Display: one row per month, single forecast value.** The history table
   shows the latest forecast only; pending (no actual) rows show `—` and are
   dimmed. No evolution arrow. Even if legacy duplicate rows exist in the DB,
   the presenter collapses them to the newest one.

No migration / schema change.

### 10.3 Implementation Steps

### Step 1 — Update-in-place (AIPredictionService)

File (MOD): `app/Services/AI/AIPredictionService.php` — `savePredictionHistory()` (lines ~581-665)

- Keep the `>= 100` guard and the predicted-month baseline resolution.
- Fulfilled-month guard (unchanged): skip when a row for the predicted month
  already has `actual_next`.
- Replace the rev-2 "create on change" dedupe with keep-latest:
  - Find the existing row for `(project_id, month_label)`.
  - If found and `current_progress` / `predicted_next` are identical → skip
    (no write on page re-view).
  - If found and different → UPDATE the row in place (`regression_method`,
    `current_progress`, `predicted_next`, `r_squared`, `regression_snapshot`).
  - If not found → CREATE.
- Legacy fallback (no predicted month resolvable): keep the old
  `current_progress + predicted_next` dedupe + create.
- Update the method docblock (rev 3 semantics).

### Step 2 — actual_next on newest row only (ProjectProgressController)

File (MOD): `app/Http/Controllers/ProjectProgressController.php` — `syncPredictionHistoryActual()` (line ~434)

Unchanged from rev 2 (already correct): write `actual_next` to the newest row
for the month; null any other rows (self-heals legacy duplicates).

### Step 3 — Latest-row-per-month history (AIPredictionPresenter)

File (MOD): `app/Services/AI/AIPredictionPresenter.php` — history build (lines ~394-437)

- Keep the 6 most recent distinct months query; fetch rows ordered by
  `month_label` asc, `id` desc.
- Group by `month_label` and take ONLY the newest row per month (drops the
  rev-2 `predicted_evolution` / `prediction_count` keys).
- Each entry: `month`, `predicted`, `actual` (or null), `variance` (or null),
  `method`, `pending`.
- Summary unchanged (months with actual).

### Step 4 — Blade single-value display

File (MOD): `resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php` (lines ~716-745)

- Predicted cell back to a single value (remove the evolution arrow block).
- Keep `—` + dim + "pending" badge for rows without an actual.
- Revert the accuracy hint to the v1 wording.

### Step 5 — AIExplanationService

No change expected: `whereNotNull('actual_next')` already yields one row per
fulfilled month. Verify only.

### Step 6 — Tests

- MOD `tests/Unit/Services/AI/AIPredictionServiceTest.php`:
  `test_second_inspection_records_evolved_prediction_for_next_month`
  → `test_second_inspection_updates_prediction_in_place` (May 4.5% UPDATES the
  single June row; count stays 2, `current_progress` becomes 4.5). Keep the
  other two history tests.
- MOD `tests/Feature/Services/AI/PredictionHistorySyncTest.php`:
  update `test_same_month_re_inspection_updates_the_correct_actual` (count stays
  2; June row refreshed, not duplicated); replace
  `test_actual_next_is_written_only_to_latest_prediction_row` with
  `test_sync_attributes_actual_to_latest_row_even_with_legacy_duplicates`;
  keep `test_no_prediction_row_created_after_month_is_fulfilled`.
- MOD `tests/Unit/Services/AI/AIPredictionPresenterTest.php`:
  update `test_prediction_history_shows_correct_month_actual`; replace
  `test_history_collapses_multiple_predictions_per_month` with
  `test_history_shows_only_latest_prediction_per_month`.

### Step 7 — Verify

- `php artisan test --filter=AIPredictionServiceTest`
- `php artisan test --filter=PredictionHistorySyncTest`
- `php artisan test --filter=AIPredictionPresenterTest`
- Full suite `php artisan test`.

### Step 8 — Clean up project 142

- Delete the spurious June rows (prediction_history ids 39, 40), keeping 41
  (6.55). One-time data cleanup.

### 10.4 Files Touched

- MOD `app/Services/AI/AIPredictionService.php`
- MOD `app/Http/Controllers/ProjectProgressController.php` (unchanged from rev 2)
- MOD `app/Services/AI/AIPredictionPresenter.php`
- MOD `resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php`
- MOD `tests/Unit/Services/AI/AIPredictionServiceTest.php`
- MOD `tests/Feature/Services/AI/PredictionHistorySyncTest.php`
- MOD `tests/Unit/Services/AI/AIPredictionPresenterTest.php`
- (verify only) `app/Services/AI/AIExplanationService.php`
- No migration / schema change.

### 10.5 Decision Log (v3)

1. **Keep-latest vs record-all (rev 2) vs keep-first (v1)** — keep-latest.
   Record-all was tried (commit-set above) and reverted after project 142:
   it preserved a data-entry mistake as a permanent row (id 40) and rewarded
   fixing it with yet another row (id 41). Keep-latest gives the clean,
   correct history the user wants AND still fixes the original v1 mismatch
   (history == forecast last seen).
2. **Forecast refresh signal** — `current_progress` / `predicted_next` change.
   A pending row is updated whenever the forecast genuinely differs; identical
   re-generation is a no-op.
3. **actual_next target** — newest row only (inherited from rev 2). With
   keep-latest this is the single row; the "null the others" step self-heals
   legacy duplicates.
4. **Fulfilled-month guard** — kept from rev 2: never create or refresh a row
   for a month whose outcome is known.
5. **Display** — single latest value per month (no evolution arrow). Legacy
   duplicates, if any, are collapsed by the presenter to the newest row.

---
END OF PLAN (rev 3)

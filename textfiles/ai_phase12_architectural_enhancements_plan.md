# AI Phase 12 — Architectural Enhancements Plan

## Purpose

Improve AI prediction performance and maintainability through two targeted changes:

1. **12.1 — Prediction Caching:** Cache expensive prediction computations per project. Currently, every page load runs the full regression/forecast/completion pipeline from scratch — including the `HistoricalSimilarityService` which scans ALL completed projects.
2. **12.3 — Configurable Thresholds:** Move ~100+ hardcoded thresholds (risk levels, fit quality, trend detection, etc.) into `config/ai.php` so PMU staff can tune them without code changes. Eliminates 3-4x duplication across files.

**Deferred:** 12.2 (Background Jobs for Gemini API) — low value, adds significant complexity for marginal UX improvement. Gemini responds in 2-5s; synchronous with spinner is standard UX for AI generation.

## Scope

### 12.1 — Prediction Caching

**Current state:** Zero caching. `Cache::`, `cache()`, `remember()` are completely absent from `app/`. The full pipeline runs on every page load including AJAX calls to `getChartData()`.

**What gets cached:** The output of `AIPredictionService::generatePrediction($project)` — the raw prediction array containing regression, forecast, completion estimation, assessment, reliability, class standing, and performance data.

**Cache key:** `ai_prediction:{project_id}` — single entry per project, invalidated on data changes.

**Invalidation:** Six controller methods trigger cache bust after their mutations complete:

| Controller | Method | Invalidation Point |
|---|---|---|
| `ProjectProgressController` | `recordMonthlyProgress()` | Before final return (~line 524) |
| `ProjectProgressController` | `editProgressRecord()` | Before final return (~line 221) |
| `ProjectProgressController` | `destroyProgress()` | Before final return (~line 336) |
| `BaselineScheduleController` | `storeBaselineSchedule()` | Before final return (~line 263) |
| `BaselineScheduleController` | `updateBaselineSchedule()` | Before final return (~line 581) |
| `BaselineScheduleController` | `storeInitialBaselineSetup()` | After commit (~line 454) |
| `Admin\ProjectExtensionController` | `store()` (auto-approve) | Before final return (~line 150) |
| `Admin\ProjectExtensionController` | `update()` (staff review) | Before final return (~line 385) |
| `Admin\ProjectExtensionController` | `update()` (admin approve) | Before final return (~line 534) |

**What is NOT cached:**
- `AIPredictionPresenter::present()` — reads cached text from `ai_solutions`, assembles Blade-ready array. This is cheap after the prediction is cached.
- `savePredictionHistory()` — side-effect write, not a computation.
- Gemini API calls — separate concern (regenerate button).

**TTL:** No time-based expiry. Cache persists until invalidated by a data change. This is safe because invalidation covers all mutation paths.

**Existing partial cache:** The `ai_solutions.regression_snapshot` column stores the full prediction output as JSON but is never read back. This plan does NOT repurpose that column — it uses Laravel's `Cache` facade for flexibility (supports Redis, file, database drivers).

### 12.3 — Configurable Thresholds

**New file:** `config/ai.php` — returns a structured array of all thresholds.

**Threshold categories to extract:**

| Category | Current Location(s) | Config Key |
|---|---|---|
| Risk gap ladder (-5, 5, 10, 15) | `ProjectStatusService`, `ProjectHealthService`, `RecommendationEngine` | `risk.gap` |
| Trend detection delta (±2) | `AIPredictionPresenter`, `RecommendationEngine`, `AIExplanationService`, `AIRecommendationService` | `trend.delta` |
| Fit quality R² cutoffs (0.90/0.80/0.70/0.50) | `LinearRegressionService`, `AIPredictionPresenter` | `fit_quality` |
| Accurate prediction threshold (±2%) | `AIPredictionPresenter`, `AIPredictionService`, `ForecastReliabilityService` | `accuracy` |
| Data sufficiency observation counts (2, 3, 4, 6) | `AIPredictionPresenter`, `AIPredictionService`, `CompletionEstimationService` | `data_sufficiency` |
| Contractor rating tiers | `ContractorPerformanceService` | `contractor_rating` |
| Aging issue days (30) | `AIRecommendationService`, `RecommendationEngine`, `ProjectOverviewService` | `notifications` |
| Minimum observations for regression (2, 4) | `AIPredictionPresenter`, `AIPredictionService` | `regression` |
| Recommendation escalation thresholds | `RecommendationEngine` | `recommendations` |
| Reliability scoring weights/floors | `ForecastReliabilityService` | `reliability` |
| Similarity weights/tolerances | `HistoricalSimilarityService` | `similarity` |
| Completion estimation confidence | `CompletionEstimationService`, `CompletionComparisonService` | `completion` |

**Access pattern:** Services read from `config('ai.risk.gap')` instead of inline literals. Class constants that are pure thresholds (e.g., `ForecastReliabilityService::R2_FLOOR`) are replaced with config reads.

**What stays as code:** Algorithm logic, branching conditions, and non-tunable constants (e.g., `MIN_R2_FOR_ESTIMATE = 0.50` could stay as code if it's a hard gate, not a tuning knob).

---

## config/ai.php Structure

```php
<?php

return [
    'cache' => [
        'prediction_ttl' => null, // null = no expiry, invalidated on data change
    ],

    'risk' => [
        // Gap variance thresholds (positive = behind schedule)
        'gap' => [
            'ahead'     => -5,   // variance <= -5 → ahead
            'on_track'  => 5,    // variance <= 5  → on track
            'low'       => 10,   // variance < 10  → low risk
            'medium'    => 15,   // variance < 15  → medium risk; >= 15 = high
        ],
    ],

    'trend' => [
        // Variance delta threshold for trend classification
        'variance_delta' => 2,   // abs(delta) >= 2 → accelerating/decelerating
    ],

    'fit_quality' => [
        'excellent' => 0.90,
        'very_good' => 0.80,
        'good'      => 0.70,
        'moderate'  => 0.50,
    ],

    'accuracy' => [
        'accurate_threshold' => 2,   // abs(variance) < 2 → accurate
        'close_threshold'    => 5,   // abs(variance) < 5 → close
    ],

    'data_sufficiency' => [
        'low'             => 3,  // observations <= 3 → low data
        'moderate'        => 6,  // observations <= 6 → moderate data
        'min_for_regression' => 2, // observations < 2 → insufficient
        'min_polynomial'  => 4,  // observations < 4 → disable polynomial
    ],

    'regression' => [
        'min_observations_for_regression' => 2,
        'min_observations_for_polynomial' => 4,
        'slr_polynomial_r2_threshold'     => 0.02, // min R² improvement for polynomial
    ],

    'accuracy' => [
        'accurate_threshold' => 2,   // abs(variance) < 2 → accurate
        'close_threshold'    => 5,   // abs(variance) < 5 → close
    ],

    'contractor_rating' => [
        // [min_on_time_pct, max_avg_delay_days] → rating
        5 => [90, 7],    // Superior
        4 => [80, 15],   // Good
        3 => [70, 30],   // Satisfactory
        2 => [50, 60],   // Below Average
        // else → Poor (1)
    ],

    'notifications' => [
        'aging_days' => 30,  // issue open > 30 days = aging
    ],

    'recommendations' => [
        'max_ai'               => 6,
        'max_rule'             => 8,
        'min_observations'     => 2,   // observations <= 2 → data-focused only
        'escalation_gap'       => -20, // progress_gap < -20 + days < 30 → urgent
        'escalation_days'      => 30,
        'systemic_issues_count' => 5,  // >= 5 issues open 30+ days
        'material_issues_count' => 3,  // >= 3 material issues
        'contractor_issues_count' => 2,
        'design_issues_count'  => 2,
        'aging_issues_count'   => 2,
        'carryover_count'      => 2,
        'carryover_gap'        => -5,
        'deadline_days'        => 15,  // days < 15 + progress < 90% → extension eval
        'deadline_progress'    => 90,
    ],

    'reliability' => [
        'high_threshold'    => 80,
        'medium_threshold'  => 55,
        'r2_floor'          => 0.50,
        'min_real_obs'      => 2,
        'honesty_fulfilled' => 3,
        'honesty_real_obs'  => 6,
        'weights' => [
            'backtest'  => 40,
            'data'      => 30,
            'horizon'   => 15,
            'agreement' => 15,
        ],
    ],

    'similarity' => [
        'weights' => [
            'category'  => 30,
            'budget'    => 25,
            'duration'  => 20,
            'contractor' => 15,
            'workers'   => 10,
        ],
        'tolerances' => [
            'budget'   => 0.20,
            'duration' => 0.30,
            'workers'  => 0.40,
        ],
        'min_score'          => 50,
        'min_similar_projects' => 3,
        'min_observations'   => 4,
        'blend_k'            => 4,
    ],

    'completion' => [
        'high_confidence_r2'       => 0.85,
        'medium_confidence_r2'     => 0.70,
        'high_confidence_obs'      => 6,
        'medium_confidence_obs'    => 4,
        'high_confidence_index_ratio' => 1.5,
        'low_confidence_index_ratio'  => 2.0,
        'agreement_high_days'      => 15,
        'agreement_moderate_days'  => 45,
    ],
];
```

---

## Implementation

### Phase 1: Create `config/ai.php`

**New file:** `config/ai.php`

1. Create the file with the structure above.
2. Use `env()` for cache driver if needed (but config values are static — no env() needed for thresholds).

### Phase 2: Prediction Caching

**File: `app/Services/AI/AIPredictionService.php`**

1. Add `use Illuminate\Support\Facades\Cache;`
2. In `generatePrediction()`, wrap the computation in `Cache::remember()`:
   ```php
   $cacheKey = 'ai_prediction:' . $project->id;
   return Cache::remember($cacheKey, null, function () use ($project) {
       // existing computation...
   });
   ```
3. `null` TTL = no time-based expiry (invalidated by data changes).

**Files: 7 controller methods — add cache invalidation**

Each controller method adds one line before its final return:

```php
Cache::forget('ai_prediction:' . $project->id);
```

| Controller | Method | Insert Before |
|---|---|---|
| `ProjectProgressController` | `recordMonthlyProgress()` | Line 526 return |
| `ProjectProgressController` | `editProgressRecord()` | Line 223 return |
| `ProjectProgressController` | `destroyProgress()` | Line 338 return |
| `BaselineScheduleController` | `storeBaselineSchedule()` | Line 265 return |
| `BaselineScheduleController` | `updateBaselineSchedule()` | Line 583 return |
| `BaselineScheduleController` | `storeInitialBaselineSetup()` | Line 456 return |
| `Admin\ProjectExtensionController` | `store()` | Line 160 return |
| `Admin\ProjectExtensionController` | `update()` (staff) | Line 387 return |
| `Admin\ProjectExtensionController` | `update()` (admin) | Line 544 return |

### Phase 3: Configurable Thresholds Migration

For each service file, replace hardcoded literals with `config()` reads:

**Files to modify (12 services):**

1. `app/Services/ProjectStatusService.php` — risk gap ladder
2. `app/Services/ProjectHealthService.php` — risk gap ladder
3. `app/Services/AI/RecommendationEngine.php` — risk gap ladder, trend delta, escalation thresholds
4. `app/Services/AI/AIPredictionPresenter.php` — trend delta, fit quality, data sufficiency, accurate threshold
5. `app/Services/AI/LinearRegressionService.php` — fit quality R² cutoffs
6. `app/Services/AI/AIPredictionService.php` — accurate threshold, regression minimums
7. `app/Services/AI/AIExplanationService.php` — trend delta
8. `app/Services/AI/AIRecommendationService.php` — trend delta, max recommendations, aging days
9. `app/Services/AI/CompletionEstimationService.php` — R² thresholds, observation counts
10. `app/Services/AI/CompletionComparisonService.php` — agreement day thresholds
11. `app/Services/AI/ForecastReliabilityService.php` — accuracy thresholds, weights, floors
12. `app/Services/AI/HistoricalSimilarityService.php` — weights, tolerances, min scores
13. `app/Services/AI/ContractorPerformanceService.php` — rating tiers
14. `app/Services/ProjectOverviewService.php` — aging days

### Phase 4: Tests

1. **Cache test:** Verify prediction is cached on first call, returned from cache on second, invalidated after progress record.
2. **Config test:** Verify `config('ai.risk.gap')` returns expected structure.
3. **Threshold migration test:** Existing tests must still pass with config values (no behavior change).
4. **Override test:** Verify changing a config value (in test) changes the service behavior.

### Phase 5: Update Documentation

- Update `ai_module_ver2_master_plan.txt` Phase 12 status to DONE.
- Update `AI_MODULE_HANDOVER.md` status.
- Update `summary_of_next_work.txt` status.

---

## Files Changed

| File | Change |
|------|--------|
| `config/ai.php` | **NEW** — all thresholds centralized |
| `app/Services/AI/AIPredictionService.php` | Add `Cache::remember()` wrapper |
| `app/Http/Controllers/ProjectProgressController.php` | Add `Cache::forget()` in 3 methods |
| `app/Http/Controllers/BaselineScheduleController.php` | Add `Cache::forget()` in 3 methods |
| `app/Http/Controllers/Admin/ProjectExtensionController.php` | Add `Cache::forget()` in 2 methods (3 paths) |
| `app/Services/ProjectStatusService.php` | `config('ai.risk.gap.*')` |
| `app/Services/ProjectHealthService.php` | `config('ai.risk.gap.*')` |
| `app/Services/AI/RecommendationEngine.php` | `config('ai.risk.*')`, `config('ai.recommendations.*')` |
| `app/Services/AI/AIPredictionPresenter.php` | `config('ai.trend.*')`, `config('ai.fit_quality.*')`, `config('ai.accuracy.*')` |
| `app/Services/AI/LinearRegressionService.php` | **Kept class constants** (pure unit tests, no Laravel container) |
| `app/Services/AI/AIExplanationService.php` | `config('ai.trend.delta')`, `config('ai.accuracy.*')` |
| `app/Services/AI/AIRecommendationService.php` | `config('ai.trend.delta')`, `config('ai.recommendations.*')` |
| `app/Services/AI/CompletionEstimationService.php` | `config('ai.completion.*')` |
| `app/Services/AI/CompletionComparisonService.php` | `config('ai.completion.*')` |
| `app/Services/AI/ForecastReliabilityService.php` | **Kept class constants** (pure unit tests, no Laravel container) |
| `app/Services/AI/HistoricalSimilarityService.php` | `config('ai.similarity.*')` |
| `app/Services/AI/ContractorPerformanceService.php` | `config('ai.contractor_rating')` |
| `app/Services/ProjectOverviewService.php` | `config('ai.notifications.aging_days')` |
| `tests/Unit/Services/AI/PredictionCacheTest.php` | **NEW** — cache behavior tests |
| `tests/Unit/Config/AiConfigTest.php` | **NEW** — config structure tests |

---

## Planned Adjustment: 8-Hour Cache TTL

### Problem
The cache currently has `null` TTL (never expires). When a project is inactive and the calendar month transitions (e.g., August 31 → September 1), the cached prediction retains the old month's `current_month_index`. The staleness persists until a user manually busts the cache by recording progress, editing baseline, or submitting an extension.

### Solution
Change `prediction_ttl` from `null` to `480` (8 hours in minutes). This ensures:

- Stale projects recompute at most once per working day (on first morning load)
- Active projects are unaffected (cache busted by user activity before TTL expires)
- Month transitions trigger recomputation on first morning load

### Why 8 hours
- Matches standard office hours (8 AM – 5 PM)
- Rolling TTL means recomputation happens ~once per day for inactive projects
- No compute waste during active hours (user activity busts cache before TTL)

### Change
File: `config/ai.php` line 17
```php
// Before
'prediction_ttl' => null,

// After
'prediction_ttl' => 480, // 8 hours in minutes
```

### Impact
- **Active projects:** No change — cache busted by user activity
- **Inactive projects:** Recomputes once on first morning load, then cached for 8 hours
- **Month transitions:** Recomputes on first morning load after transition
- **Compute cost:** At most 3 recomputes per day per inactive project (negligible)

### Status
- **Planned** — not yet implemented
- Implementation: one-line config change

---

## Implementation Log

### Phase 1: config/ai.php (commit TBD)

**New file:** `config/ai.php` — 279 lines, 11 threshold categories.

Categories and their config keys:
- `cache` — prediction cache TTL (currently `null`)
- `risk.gap` — risk level boundaries (-5, 5, 10, 15)
- `trend.variance_delta` — trend detection sensitivity (2)
- `fit_quality` — R² labels (0.90/0.80/0.70/0.50)
- `accuracy` — prediction accuracy thresholds (2, 5)
- `data_sufficiency` — observation count gates (3, 6, 2, 4)
- `regression` — SLR vs polynomial R² improvement threshold (0.02)
- `contractor_rating` — 5-tier rating with on-time% and avg delay days
- `notifications.aging_days` — issue aging threshold (30)
- `recommendations` — 20 thresholds for escalation/corrective/monitoring/admin rules
- `reliability` — scoring weights, data tiers, horizon tiers, blend shrink params
- `similarity` — 5 similarity factor weights, 3 tolerances, min score, blend K, scale ratios
- `completion` — confidence thresholds (R², obs, index ratio), agreement days

### Phase 2: Caching (commit TBD)

**File: `app/Services/AI/AIPredictionService.php`**
- Added `use Illuminate\Support\Facades\Cache;`
- Removed `private const SLR_POLYNOMIAL_R2_THRESHOLD = 0.02;` — replaced with `config('ai.regression.slr_polynomial_r2_threshold')`
- `generatePrediction()` now wraps computation in `Cache::remember('ai_prediction:' . $project->id, config('ai.cache.prediction_ttl'), fn() => computePrediction())`
- Computation extracted to `private computePrediction()` — same logic, separate method for cache closure

**9 controller invalidation points (Cache::forget):**

| Controller | Method | What triggers bust |
|---|---|---|
| `ProjectProgressController` | `recordMonthlyProgress()` | New progress recorded |
| `ProjectProgressController` | `editProgressRecord()` | Progress edited |
| `ProjectProgressController` | `destroyProgress()` | Progress deleted |
| `BaselineScheduleController` | `storeBaselineSchedule()` | Baseline month target added |
| `BaselineScheduleController` | `updateBaselineSchedule()` | Baseline month target updated |
| `BaselineScheduleController` | `storeInitialBaselineSetup()` | Baseline locked |
| `Admin\ProjectExtensionController` | `store()` | Extension submitted |
| `Admin\ProjectExtensionController` | `update()` (staff) | Extension approved/rejected by staff |
| `Admin\ProjectExtensionController` | `update()` (admin) | Extension approved/rejected/reverted by admin |

Each controller also had `use Illuminate\Support\Facades\Cache;` added to imports.

### Phase 3: Threshold Migration (commit TBD)

12 of 14 service files migrated from hardcoded constants/literals to `config('ai.*')` reads:

**`app/Services/ProjectStatusService.php`** — Risk gap ladder
- Replaced 4 hardcoded comparisons (`<= -5`, `<= 5`, `< 10`, `< 15`) with `config('ai.risk.gap.*')`

**`app/Services/ProjectHealthService.php`** — Risk gap ladder
- Same pattern as ProjectStatusService: 4 comparisons → config reads

**`app/Services/AI/RecommendationEngine.php`** — Most changes (74 lines)
- `MAX_RECOMMENDATIONS` constant → `maxRecommendations()` method reading `config('ai.recommendations.max_rule')`
- `assessEscalation()`: hardcoded `5` → `config('ai.recommendations.systemic_issues_count')`, hardcoded `-20`/`30` → config
- `assessCorrective()`: 8 hardcoded thresholds → config reads (`-10`, `3`, `2`, rating checks, `2`, `2`, `-5`)
- `assessMonitoring()`: open count threshold → config
- `assessAdministrative()`: deadline days/progress → config
- `determineTrend()`: delta `2` → `config('ai.trend.variance_delta')`
- `classifyRiskFromGap()`: 4 gap thresholds → `config('ai.risk.gap')`
- `mergeAndSort()`: cap uses `maxRecommendations()` method

**`app/Services/AI/AIPredictionPresenter.php`** — 22 lines changed
- `presentNormal()`: accurate threshold (`2`) → config, health confidence obs counts → config
- `generateCertaintyLabel()`: data cert thresholds (3, 6) → config, fit quality (0.90/0.70/0.50) → config
- `determineTrend()`: delta `2` → config

**`app/Services/AI/AIExplanationService.php`** — 8 lines changed
- `buildPrompt()`: accurate threshold (`2`) → `config('ai.accuracy.accurate_threshold')`
- `determineTrendFromAssessment()`: delta `2` → config

**`app/Services/AI/AIRecommendationService.php`** — 17 lines changed
- `MAX_RECOMMENDATIONS` constant → `maxRecommendations()` method reading config
- `buildIssuesSection()`: aging days `30` → `config('ai.notifications.aging_days')`
- `determineTrend()`: delta `2` → config

**`app/Services/AI/CompletionEstimationService.php`** — Most deletions (45 lines removed)
- Removed 8 class constants: `MAX_REASONABLE_MONTHS`, `HIGH_CONFIDENCE_R2`, `MEDIUM_CONFIDENCE_R2`, `HIGH_CONFIDENCE_OBS`, `MEDIUM_CONFIDENCE_OBS`, `HIGH_CONFIDENCE_INDEX_RATIO`, `LOW_CONFIDENCE_INDEX_RATIO`, `MIN_R2_FOR_ESTIMATE`
- All replaced with `config('ai.completion.*')` reads across `estimate()`, `estimatePolynomial()`, `isPolynomialSelected()`, `estimateFromClassReference()`, `estimateEarnedSchedule()`, `classifyConfidence()`

**`app/Services/AI/CompletionComparisonService.php`** — 6 lines changed
- Agreement spread thresholds (15, 45) → `config('ai.completion.agreement')`

**`app/Services/AI/HistoricalSimilarityService.php`** — Largest refactor (84 lines changed)
- Removed 15 class constants (25 occurrences): `SIMILARITY_WEIGHT_*`, `BUDGET_TOLERANCE`, `DURATION_TOLERANCE`, `WORKERS_TOLERANCE`, `MIN_OBSERVATIONS_FOR_CURVE`, `MIN_SIMILAR_PROJECTS`, `MIN_SIMILARITY_SCORE`, `MIN_SCALE_RATIO`, `MAX_SCALE_RATIO`, `AGGREGATE_INTERVAL`, `BLEND_K`
- All replaced with `config('ai.similarity.*')` reads
- `availableSimilarityWeight()`: 5 weight constants → config
- `computeSimilarityBreakdown()`: weights + tolerances → config
- `buildAggregateCurve()`: min observations, min projects, interval → config
- `blendWeight()`: blend K → config
- `buildClassReference()`: scale ratio min/max → config
- `generateSyntheticPoints()`: scale ratio min/max → config
- `getSimilarProjectsDetails()`: min observations → config

**`app/Services/AI/ContractorPerformanceService.php`** — 18 lines changed
- `getPerformanceRating()`: 4 hardcoded if-else tiers → loop over `config('ai.contractor_rating')`

**`app/Services/ProjectOverviewService.php`** — 2 lines changed
- Aging days `30` → `config('ai.notifications.aging_days')`

**Exceptions (NOT migrated):**
- `LinearRegressionService` — kept class constants (pure unit tests extending `PHPUnit\Framework\TestCase`, no Laravel container)
- `ForecastReliabilityService` — kept class constants (same reason)

### Phase 4: Tests (commit TBD)

**`tests/Unit/Services/AI/AIPredictionServiceTest.php`**
- Added `use Illuminate\Support\Facades\Cache;`
- Added `Cache::forget('ai_prediction:' . $project->id)` before re-generation calls in:
  - `test_prediction_history_persists_and_refreshes_band()` (line 454)
  - `test_second_inspection_updates_prediction_in_place()` (lines 679, 686)

### Test Results (2026-08-27)
- **849 passed, 2 failed** (2 pre-existing `EmailDraftTest` failures unrelated to Phase 12)
- No new failures introduced

### Branch
- `ai/phase12-architectural-enhancements`
- Plan committed at `395ae1e`
- Changelog update committed at `a511d8f`

---

## Uncommitted Changes Summary

All changes are on branch `ai/phase12-architectural-enhancements`. Files listed by change type:

**NEW file (1):**
- `config/ai.php` — centralized AI thresholds (279 lines)

**MODIFIED files (16):**
- `app/Services/AI/AIPredictionService.php` — Cache wrapper + config reads
- `app/Http/Controllers/ProjectProgressController.php` — Cache::forget in 3 methods
- `app/Http/Controllers/BaselineScheduleController.php` — Cache::forget in 3 methods
- `app/Http/Controllers/Admin/ProjectExtensionController.php` — Cache::forget in 3 paths
- `app/Services/ProjectStatusService.php` — risk gap → config
- `app/Services/ProjectHealthService.php` — risk gap → config
- `app/Services/AI/RecommendationEngine.php` — 20+ thresholds → config
- `app/Services/AI/AIPredictionPresenter.php` — fit/accuracy/data thresholds → config
- `app/Services/AI/AIExplanationService.php` — trend delta + accuracy → config
- `app/Services/AI/AIRecommendationService.php` — max recs, aging, trend → config
- `app/Services/AI/CompletionEstimationService.php` — 8 constants removed → config
- `app/Services/AI/CompletionComparisonService.php` — agreement days → config
- `app/Services/AI/HistoricalSimilarityService.php` — 15 constants removed → config
- `app/Services/AI/ContractorPerformanceService.php` — rating tiers → config
- `app/Services/ProjectOverviewService.php` — aging days → config
- `tests/Unit/Services/AI/AIPredictionServiceTest.php` — Cache import + cache busting in tests

**NOT modified (kept constants):**
- `app/Services/AI/Regression/LinearRegressionService.php` — pure unit tests, no Laravel
- `app/Services/AI/ForecastReliabilityService.php` — pure unit tests, no Laravel

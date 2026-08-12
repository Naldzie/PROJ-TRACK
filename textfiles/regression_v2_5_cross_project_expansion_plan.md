# Regression Module v2.5 — Cross-Project Enhancement Plan

CSPC PMU System — AI Module Version 2.5 (follow-up to Phase 8 / ai_v2_phase8_cross_project_progress_log.txt)

Status: 📋 DRAFT — planned, NOT started. User intends to build other missing
        features first, then return here. See §7 for the v2 sufficiency assessment.

Date: August 1, 2026

> ⚠️ RELATED PLANNED WORK (added Aug 11, 2026): V2 Phase 14 — "Forecast
> Reliability & Prediction Confidence Rebase"
> (textfiles/forecast_reliability_confidence_plan.md) replaces the R²-as-
> confidence wording with a weighted reliability score. Two links to this
> plan:
> - The reliability **data** factor must use the REAL observation count
>   (observation_count minus synthetic_points_count), fixing the known
>   key-path bug (AIPredictionService.php:245) so confidence and the
>   reliability score agree in supplement mode.
> - Option **A3 (prediction interval)** below naturally extends the
>   reliability factor breakdown into a visible range; the cross-project
>   P25/P50/P75 aggregate bands can feed it.
> Suggested order: implement Phase 14 before or together with A2/A3.

Reference:
- textfiles/active_reference/phase8_cross_project_regression_plan.txt
- textfiles/active_reference/ai_v2_phase8_cross_project_progress_log.txt
- textfiles/progress_inspection_refactor_plan.md → Fix H (non-monotonic polynomial)

---

## 1. WHY THIS PLAN EXISTS

Cross-Project Historical Regression (Phase 8) is currently used in EXACTLY ONE
way: as a data supplement for projects with too few observations. Verified by
tracing every usage (2026-08-01):

- The only hook is `AIPredictionService::applyCrossProjectData()`
  (AIPredictionService.php:212), called BEFORE regression.
- `HistoricalSimilarityService::shouldSupplement()` gates it:
  - 0 obs → `none` (project shows "Insufficient Data"; cross-project never helps)
  - 1 obs or < ⅓ planned months → `supplement` (real + synthetic, `data_source = 'hybrid'`)
  - ≥ threshold → `none` (cross-project is NEVER consulted for normal projects)
- After the override, the data flows through the whole pipeline (regression →
  next prediction → forecast → assessment → completion), and
  `synthetic_points_count` also lowers completion-estimation confidence — but
  ONLY for sparse projects.
- For every non-sparse project, `HistoricalSimilarityService` (aggregate curve,
  similar projects) is completely idle.

So today the feature is "supplement for sparse data" and nothing else. This plan
collects additional ways the cross-project data can power the regression-based
predictive module, so the work isn't forgotten.

---

## 2. CURRENT STATE (verified working, 2026-08-01)

- `HistoricalSimilarityService` + supplement path unaffected by the
  inspection_date refactor (all ordering is `month_label`).
- Full suite: 186 passed / 641 assertions, including new
  `tests/Feature/Services/AI/CrossProjectSupplementIntegrationTest.php`
  (5 tests) that finally exercise the supplement path end-to-end
  (fills Open Item #3 of the Phase 8 log).

---

## 3. EXPANSION OPTIONS

### A. Regression-level (change the math; can apply to ALL projects)

#### A1 — Model blend / prior (extends beyond sparse)
Instead of ONLY prepending synthetic points when sparse, blend the project's own
regression with the category curve as a weak prior. The blend weight shifts from
"mostly cross-project" (few observations) to "mostly own data" (rich history).
One mechanism covers all projects, replaces the "prepend points" hack, and can
smooth the weird polynomial tails.

- Where: `AIPredictionService::runRegression()` / `predictNextMonth()`,
  `RegressionDatasetBuilder`.
- Effort: HIGH. Risk: HIGH — changes every prediction, biggest behavioral shift.
- Note: overlaps with the decision in Fix H (fall back to SLR vs. clamp).

#### A2 — Completion sanity fallback (ties into Fix H)
When the project's own model produces an implausible completion (e.g. project 142
polynomial → past date, or absurd month count), estimate "how long similar
projects took to go from X% → 100%" from the aggregate and blend/bound the answer.

- Where: `CompletionEstimationService::estimatePolynomial()` (and friends).
- Effort: MEDIUM. Risk: MEDIUM (only affects the implausible branch).
- Directly complements Fix H item 3 in the refactor plan.

#### A3 — Prediction interval / confidence band
The aggregate already carries median/lower/upper quartiles. Surface the
next-month prediction as a RANGE ("88% ± 6") and/or a percentile instead of a
single number.

- Where: `AIPredictionPresenter` output + ai-prediction.blade.php.
- Effort: LOW-MEDIUM. Risk: LOW (additive display).

#### A4 — Fallback for brand-new (0-obs) projects  ✅ DONE (Aug 13 2026)
`HistoricalSimilarityService::generateFallbackDataset()` exists and builds a
full synthetic curve, but was UNREACHABLE because `shouldSupplement()` returned
`none` for 0 observations. Product decision (Aug 13 2026): a 0-obs project
with a valid pool shows a clearly-labeled "category baseline" curve.
- shouldSupplement(): 0 obs + planned duration → 'fallback'; 0 obs + no
  planned duration → 'none'. Pool gate is downstream (buildAggregateCurve
  returns [] if < MIN_SIMILAR_PROJECTS usable curves → fallback null →
  existing Insufficient Data path).
- generateFallbackDataset(): current_month_index = 1 (project at start, not
  end of plan) so downstream reads y[0] and forecasts from month 1.
- Honest labeling: data_source 'similar_projects' with 0 real obs →
  "Category baseline estimate (N similar projects) — not project-specific";
  blade badge "Category Baseline" (dead 'cross_project_fallback' check removed);
  disclaimer "not a statement about this project's own history".
- Reliability: untouched — Phase 14 real_obs_count = obs − synthetic = 0 →
  floor confidence automatically.
- Tests: CrossProjectSupplementIntegrationTest (zero-obs fallback/no-pool +
  A4 e2e + baseline render), HistoricalSimilarityHardeningTest
  (presenter category-baseline wording). Full suite 627 passed / 2353.

### B. Benchmark / insight layer (no regression change)

#### B5 — Percentile benchmark
Plot the project's actuals against the aggregate median/lower/upper band →
"your progress is in the 72nd percentile of similar completed projects." Feeds
the health gauge / status card.

#### B6 — At-risk anomaly flag
Actual below the category lower band for 2+ consecutive months → flag "behind
similar projects of this type" → feeds RecommendationEngine.

- Effort B5/B6: LOW-MEDIUM. Risk: LOW (display/insight only).

### C. Hardening / analytics (improves every use above)

#### C7 — Track prediction accuracy by data_source  ✅ DONE (Aug 13 2026)
Log each prediction with its `data_source`; later compare error rates of
supplemented vs actual-only predictions to validate/tune weights & thresholds.
(Phase 8 log Open Item Q6.)
- PredictionHistory gains nullable `data_source` (project_history / hybrid /
  similar_projects). savePredictionHistory records it on create AND refresh;
  legacy rows stay null (grouped as project_history).
- AIPredictionService::predictionAccuracyByDataSource() aggregates fulfilled
  rows per source: count, accurate_count (<2 variance, Phase 7 convention),
  accuracy_percent, avg_abs_error, avg_variance.
- Dev view: GET /dev/prediction-accuracy → JSON breakdown (non-production).
- Tests: tests/Feature/Services/AI/PredictionAccuracyByDataSourceTest.php
  (4 tests). Full suite 623 passed / 2339 assertions.

#### C8 — Recency weighting + MIN_SIMILAR_PROJECTS gate
- Add a `MIN_SIMILAR_PROJECTS` constant (default 3): if fewer similar completed
  projects match, fallback/supplement returns null and the system uses the
  project's own data with a note. (Log: "recommended before defense".)
- Add a time-decay multiplier on similarity score based on the candidate's
  completion_date (newer projects weighted higher).

- Effort: LOW. Risk: LOW. Improves pool quality for all options above.

---

## 4. TRADEOFF SUMMARY

| Option | Applies to | Effort | Risk | Type |
|--------|-----------|--------|------|------|
| A1 model blend | all projects | High | High | math change |
| A2 completion fallback | all projects (implausible case) | Medium | Medium | math change |
| A3 prediction interval | all projects | Low-Med | Low | display |
| A4 fallback for 0-obs | brand-new projects | Low | Low | product + display |
| B5 percentile benchmark | all projects | Low-Med | Low | insight/UI |
| B6 at-risk flag | all projects | Low-Med | Low | insight/recommendations |
| C7 accuracy by data_source | analytics | Low | Low | metrics |
| C8 recency + min-projects gate | pool quality | Low | Low | hardening |

Suggested priority: C8 → A2 (+ Fix H) → A3/B5 → B6 → A4 → C7 → A1.

---

## 5. RELATIONSHIP TO Fix H

Fix H (progress_inspection_refactor_plan.md §3) fixes the non-monotonic
polynomial that makes the next prediction drop below current progress and makes
completion estimation emit past dates. Option **A2** here is the cross-project
flavored version of Fix H item 3: when the own-model answer is implausible,
fall back to the aggregate category curve. Do A2 together with Fix H so the two
don't fight.

---

## 6. VERIFICATION

- Extend `CrossProjectSupplementIntegrationTest` for any new path (A4, A2, A3).
- Keep the data_source badge/disclaimer honest when adding benchmark/interval UI.
- Re-run full suite (`php artisan test`) before merging.

---

## 7. IS THE CURRENT CROSS-PROJECT GOOD ENOUGH FOR V2?

Assessment (2026-08-01):

- **Functionally YES.** Supplement mode works end-to-end, is refactor-proof, and
  is now covered by integration tests (186 tests / 641 assertions green).
- **Two Phase 8 "open items" are still unresolved and may be considered part of
  "the incomplete phase":**
  1. Health Gauge wrong status when Progress Gap = 0 (suspects completion
     estimate using synthetic data) — investigate before defense.
  2. `completion_multi` (three-method comparison) may not render for
     supplement-mode predictions — verify.
- **Cheap hardening recommended before calling v2 "done":** C8 (MIN_SIMILAR_PROJECTS
  gate + ratio floor ~0.5) — both were flagged "recommended before defense" in
  the Phase 8 log and cost little.
- Everything in §3 is genuinely v2.5+ scope; nothing there is required for v2.

Bottom line: current cross-project is sufficient for v2 as long as open items 1–2
from the Phase 8 log are resolved and (ideally) the C8 gate is added. All §3
expansions can wait for v2.5.

---

END OF PLAN (draft — v2.5 scope; not started)

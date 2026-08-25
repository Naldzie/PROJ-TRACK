# AI / Regression Module — Handover & Reference

**Project:** CSPC PMU System (PROJ-TRACK) — Laravel 12 / PHP 8.2
**Created:** August 17, 2026
**Status:** ACTIVE / REFERENCE — this is the single entry point for the AI/regression module
**Why this file exists:** responsibility transfer. The original author is moving to a different problem; a teammate (human or AI agent) will continue this module. Read this file BEFORE reading any other AI doc — it tells you what exists, what is done, what is next, and which files hold the details.

---

## 1. What this module is (30-second summary, no code)

The AI module turns a project's **monthly progress history** (cumulative % complete) into monitoring intelligence:

1. It fits a **regression curve** to the real progress points — a straight line (simple linear regression) or a cubic curve (polynomial regression) — whichever fits better.
2. It **predicts next month's progress** and every remaining month of the project (forecast).
3. It compares the forecast to the **baseline schedule** and labels each month **On Track / Behind Schedule** with a risk level (Low/Moderate/High/Critical).
4. It estimates **when the project will actually finish** using three independent methods (conservative schedule-aware, best-case polynomial, earned-schedule EAC_time) and shows agreement between them.
5. It computes a **reliability score (0–100)** telling the user how much to trust the prediction.
6. It generates **rule-based recommendations** (escalate / corrective action / monitoring / admin).
7. It optionally writes a **natural-language analysis** using Google Gemini (stored, emailed monthly).

**Important honesty rule baked into the design:** this is NOT a trained ML model. It is transparent, explainable math. R² is presented only as "Curve Fit Quality (matches past data only)" — never as forecast confidence. When data is thin, the module either blends in data from similar completed projects or says "insufficient data" honestly.

**Where the user sees it:** the project detail page → Overview tab → "AI Prediction" card (gauge, next-month band, reliability, detailed analysis, timeline forecast, 3-method completion card, prediction history table). Admin also has a **Contractor Rating** page (AI-suggested ratings linked to each project's AI analysis) and a **monthly AI report email** to contractors.

---

## 2. Code map (AI-readable)

### 2.1 Entry points & data flow

```
Browser: Project Detail → Overview tab
   └─ ProjectController::show()  (app/Http/Controllers/ProjectController.php)
        └─ ProjectDetailService::prepareProjectData()  (app/Services/ProjectDetailService.php)
             └─ AIPredictionPresenter::present(Project)   (app/Services/AI/AIPredictionPresenter.php)
                  └─ AIPredictionService::generatePrediction(Project)   ← ORCHESTRATOR
                       ├─ RegressionDatasetBuilder::buildDataset()
                       ├─ HistoricalSimilarityService  (supplement/fallback/blend/class-standing)
                       ├─ LinearRegressionService / PolynomialRegressionService
                       ├─ ForecastService::generateFutureForecast()
                       ├─ BaselineComparisonService::compare()
                       ├─ ProjectAssessmentService::assess()
                       ├─ CompletionEstimationService / CompletionComparisonService
                       ├─ ForecastReliabilityService
                       └─ PredictionHistory (model)  — save & backtest
                  └─ RecommendationEngine::generate(Project, prediction)   (rule-based)
```

Other entry points:
- **Charts AJAX:** `ProjectController::getChartData()` → S-curve + forecast + completion chart data (public/js/admin/projects/s-curve-chart.js).
- **Regenerate analysis (Gemini):** `ProjectController::regenerateAnalysis()` → `AIExplanationService::generate()` (app/Services/AI/AIExplanationService.php), persists to `ai_solutions`.
- **Dev-only accuracy backtest:** `Admin\DevPredictionAccuracyController` → `AIPredictionService::predictionAccuracyByDataSource()` at `GET /dev/prediction-accuracy` (non-production).
- **Monthly email:** command `reports:send-ai-monthly` (app/Console/Commands/SendMonthlyAiReports.php) → `AIReportSendingService` (app/Services/AIReportSendingService.php) → `AiAnalysisMail` (app/Mail/AiAnalysisMail.php). Scheduled `monthlyOn(1, '08:00')` in `routes/console.php`. Needs a running queue worker.

### 2.2 Services — one by one

All paths relative to `app/Services/AI/`.

**Regression math (pure PHP, no DB):**

| Service | Purpose | Key methods | Notes / constants |
|---|---|---|---|
| `Regression/LinearRegressionService.php` | Simple linear regression `y = mx + b` | `analyzeDataset(x[], y[])`, `predict(monthIndex, slope, intercept)`, `predictUsingAnalysis()` | Needs ≥2 obs; R² = `1 − SSres/SStot` (1.0 when SStot=0); fit bands R² ≥0.90 Excellent, ≥0.80 Very Good, ≥0.70 Good, ≥0.50 Moderate, else Weak |
| `Regression/PolynomialRegressionService.php` | Cubic regression `y = ax³+bx²+cx+d` | `analyzeDataset()`, `predict()` | Solves 4×4 normal equations via Gaussian elimination w/ partial pivoting (pivot ε `1.0e-12`); needs **≥4 obs** else `InvalidArgumentException`; singular matrix → `RuntimeException` |
| `Regression/RegressionDatasetBuilder.php` | Builds normalized dataset from a project's progress history | `buildDataset(Project): {x, y, records, statistics}` | **One observation per calendar month** — when multiple inspections exist in the same month, only the latest (`inspection_date` desc, then `id` desc) is kept. `x` = project-relative month index (1-based), `y` = `actual_progress_percent` |

**Orchestration & analysis:**

| Service | Purpose | Key methods | Notes / constants |
|---|---|---|---|
| `AIPredictionService.php` | **THE ORCHESTRATOR** — runs the whole pipeline, produces the Prediction Report | `generatePrediction(Project): array`, `predictionAccuracyByDataSource(): array` | Constant `SLR_POLYNOMIAL_R2_THRESHOLD = 0.02` (polynomial selected only if its R² beats SLR by ≥0.02). Report keys: `current_state`, `next_prediction` (+`progress_low/high/margin`), `forecast`, `assessment`, `regression`, `completion`, `completion_multi`, `reliability`, `class_standing`, `statistics`. Band margin = `max(fitResidualRMSE, backtestAvgAbsError, 2.0)` |
| `AIPredictionPresenter.php` | Converts raw report → blade-friendly keys; handles Completed / insufficient-data / pure-baseline cases | `present(Project): array` | 1444 lines. Risk from gap (≤5 Low, <10 Moderate, <15 High, ≥15 Critical; negative → Low). Trend from last two variances (Δ≤−2 DECELERATING, Δ≥2 ACCELERATING). Health confidence by **real** obs (≥6 High, ≥3 Medium, else Low). A4 override: pure category-baseline → status "NO ACTUAL DATA" |
| `AIExplanationService.php` | Gemini natural-language analysis | `generate(Project): string`, `getLatest(Project): ?AiSolution` | Config `services.gemini.key` / `services.gemini.model` (default `gemini-1.5-flash`), 30s timeout, any failure → empty string (fallback to static text). Prompt gets regression, reliability, accuracy history (≤6 rows), 3 completion methods, issues (≤5). Persists to `ai_solutions` with full `regression_snapshot` |
| `BaselineComparisonService.php` | Forecast vs baseline variance | `compare(array $forecast): array` | Pure: `variance = predicted − planned`, rounded 2dp |
| `ProjectAssessmentService.php` | Per-month business status/risk | `assess(array $comparison): array` | variance ≥0 → On Track / Low risk; ≥−10 Moderate; ≥−25 High; else Critical |
| `ForecastService.php` | Forecast every remaining baseline month | `generateFutureForecast(...): array` | **Fix-H monotonic floor:** each point `max(floor, min(100, predicted))`, floor seeded at latest actual and propagated. Blend mode: `point = w·own + (1−w)·classMedian` |
| `HistoricalSimilarityService.php` | Cross-project intelligence (similar projects, class curve, blend weight, fallback datasets, B5/B6) | `shouldSupplement()`, `findSimilarProjects()`, `buildAggregateCurve()`, `blendWeight(obs)`, `buildClassReference()`, `generateFallbackDataset()`, `generateSupplementDataset()` | **Constants:** similarity weights category 30 / budget 25 / duration 20 / contractor 15 / workers 10; tolerances budget 0.20 / duration 0.30 / workers 0.40; `MIN_OBSERVATIONS_FOR_CURVE = 4`, `MIN_SIMILAR_PROJECTS = 3`, `MIN_SIMILARITY_SCORE = 50`, `MIN_SCALE_RATIO = 0.5`, `MAX_SCALE_RATIO = 5.0`, `AGGREGATE_INTERVAL = 5`, `BLEND_K = 4`. Recency: full weight ≤2 yrs, decays to 0.5 at 5 yrs. `shouldSupplement()`: ≥2 obs → `'none'` (blend replaces old supplement path); 1 obs → `'supplement'`; 0 obs + planned duration → `'fallback'` |
| `ForecastReliabilityService.php` | Weighted reliability score 0–100 + High/Medium/Low | `computeForNextPrediction(array $context)`, `computeForCompletion(array $context)` | **Weights:** backtest 40 / data 30 / horizon 15 / agreement 15. `R2_FLOOR = 0.50` (hard floor → Low), `MIN_REAL_OBS = 2`, High ≥80 / Medium ≥55. Honesty cap: High requires fulfilled ≥3 **OR** realObs ≥6. Backtest point/coverage 0.6/0.4. Blend shrink: class<3 ×0.80, 3–5 ×0.90, spread>25 ×0.85, >15 ×0.92, floor 0.60, never increases |
| `CompletionEstimationService.php` | 3 completion solvers + class fallback | `estimate()` (schedule-aware), `estimatePolynomial()` (Newton–Raphson), `estimateFromClassReference()`, `estimateEarnedSchedule()` | `MAX_REASONABLE_MONTHS = 1000`, `MIN_R2_FOR_ESTIMATE = 0.50`, confidence High R²≥0.85 & realObs≥6 & index ≤1.5×planned; Medium R²≥0.70 & realObs≥4; else Low; index >2× planned → Low. Newton–Raphson: max 100 iters, tol `0.0001`, rejects descending tail (`derivativeAtRoot < 0`), root must be > current index. Real obs = obs − synthetic |
| `CompletionComparisonService.php` | Runs all 3 methods + comparison summary | `estimateAll(...): array` | Labels: `polynomial` → "Polynomial (Trend-Based)", `schedule_aware` → "Schedule-Aware (Conservative)", `earned_schedule` → "Earned Schedule EAC_time". Agreement: <2 valid → N/A; spread ≤15d High; ≤45d Moderate; else Low |
| `RecommendationEngine.php` | **Rule-based** (not AI) recommendations | `generate(Project, prediction): array`, `getCategoryColor()` | `MAX_RECOMMENDATIONS = 8`. Categories: escalation (red), corrective (amber), monitoring (yellow), administrative (blue). Consumes risk, trend, gap, days left, extensions, issues (severity weights Critical 3 / High 2 / Medium 1 / Low 0.5), fit, obs, speed, baselines, carryovers, B5/B6 class standing. See `textfiles/ai_recommendation_data_gaps.txt` for what data would enable AI-driven recs |
| `ProjectAssessmentService.php` | (see table row above) | | |
| `ProjectDetailService.php` | (app/Services/ProjectDetailService.php) prepares server-side `ai_prediction` for the Overview tab; charts deferred to AJAX | `prepareProjectData()`, `prepareForecastData()` | Bridge between controller and presenter |

### 2.3 Models & schema

| Model | File | Notes |
|---|---|---|
| `PredictionHistory` | app/Models/PredictionHistory.php | `UPDATED_AT = null`. Columns: `project_id`, `month_label`, `baseline_schedule_id` (nullable, nullOnDelete, references the **predicted month's** baseline), `regression_method`, `data_source`, `current_progress`, `predicted_next`, `predicted_low`/`predicted_high` (nullable band), `actual_next`, `r_squared`, `regression_snapshot` (json) |
| `AiSolution` | app/Models/AiSolution.php | `UPDATED_AT = null`. Columns: `project_id`, `regression_snapshot` (json), `model_used`, `analysis_text` |
| `ContractorRating` | app/Models/ContractorRating.php | `contractor_id`, `project_id`, `ai_solution_id` (nullable), `rating` (1–5), `ai_review_text`, `created_by`. Unique(`contractor_id`, `project_id`) |
| `Project` | app/Models/Project.php | AI-relevant: `progressHistory()`, `baselineSchedules()`, `aiSolutions()`, `predictionHistory()`, `activityCarryovers()`, `category()`, `contractor()`, `issues()`, `risks()`. Helpers: `getProgressPercentage()`, `getDaysRemaining()`, `isOverdue()`, `isAtRisk()` (<15 days & <90%), `getCompletionDelay()`, `getEstimatedCompletionDate()` |

### 2.4 Controllers & routes

| Controller | Purpose | Routes |
|---|---|---|
| `ProjectController` (app/Http/Controllers/ProjectController.php, ~1386 lines) | Shared by admin/staff/contractor (role authorization inside controller) | `GET .../projects/{id}` (show — builds AI prediction), `GET .../projects/{id}/chart-data`, `POST .../projects/{project}/regenerate-analysis`, `DELETE /projects/{id}` (purges `aiSolutions()`, `predictionHistory()`, `activityCarryovers()`) |
| `Admin\RatingController` (app/Http/Controllers/Admin/RatingController.php) | Contractor ratings + AI suggestions + leaderboard | `GET /admin/rating`, `POST /admin/rating/suggest`, `POST /admin/rating/store`, `POST /admin/rating/auto-rate-all` |
| `Admin\DevPredictionAccuracyController` (app/Http/Controllers/Admin/DevPredictionAccuracyController.php) | Dev-only accuracy by data_source (C7) | `GET /dev/prediction-accuracy` (non-production only) |
| `Admin\EmailController` | Manual send of AI report emails | `POST /admin/email/send-now`, `POST /admin/email/send-one` |

**Rating rule table (`RatingController::computeRating()`)** — AI-suggested stars from the regression snapshot:
- variance ≥0 & delay ≤0 → 4–5★
- variance ≥−10 & delay ≤15 → 3–4★
- variance ≥−20 / delay ≤30 → 3★
- variance ≥−35 / delay ≤50 → 2★
- otherwise → 1★

### 2.5 Views & JS

- `resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php` (~1053 lines) — the AI Prediction card: gauge (JustGage+Raphael), next-month band + reliability badge, Detailed Analysis (Regenerate button), Model Summary (equation + SLR-vs-poly R² bars + method reason + similarity disclaimer), Progress Speed Comparison, Behind-Milestone alert, Recommendations, Timeline Forecast, Multi-Method Completion card, Prediction History & Accuracy table.
- `resources/views/projects/project_detail/components/tabs/overview.blade.php` — embeds the AI prediction component (lines ~197–199).
- `resources/views/projects/project_detail/components/tabs/progress.blade.php` — lazy-loads S-curve chart on first visit.
- `resources/views/admin/rating.blade.php` — 3-panel rating workspace.
- `resources/views/emails/ai-analysis.blade.php` — monthly AI report email (tracking pixel `/track/{token}`).
- `public/js/admin/projects/s-curve-chart.js` — `createSCurveChart()`, `createCompletionForecastChart()` (Chart.js).

### 2.6 data_source taxonomy

| `data_source` | Meaning | Disclaimer shown |
|---|---|---|
| `project_history` | Own real data, ≥2 obs (or no usable similarity pool) | — |
| `hybrid` | 1 real obs + synthetic points borrowed from similar projects | "Prediction supplemented with data from N similar completed project(s)…" |
| `similar_projects` | 0 real obs — pure category-baseline curve (A4) | "Category baseline estimate…not a statement about this project's own history"; presenter shows "NO ACTUAL DATA" |
| `blended` | ≥2 real obs blended against class reference curve (v2.6) | "Prediction blended with category reference curve (N similar project(s), weight w)…" |

### 2.7 Behavioral rules that MUST be preserved (learned the hard way)

1. **Fix-H monotonicity:** cumulative progress predictions/forecasts never decrease — clamped `max(currentProgress, min(100, predicted))`; forecast floor propagated row to row.
2. **Real vs synthetic observation counting:** synthetic points (supplement/fallback) are excluded from reliability, completion confidence, and health confidence. `realObsCount = max(0, observation_count − synthetic_count)`.
3. **R² is a fit metric, not confidence.** Always relabeled "Curve Fit Quality (matches past data only)" in the presenter, explanation prompt, and email.
4. **`month_number` column is unreliable** — different writers disagree on semantics. Project-relative month index is derived from `month_label` anchored to the plan's **first baseline month** (`projectRelativeMonthIndex()`). Do not reintroduce stored month_number for regression math.
5. **Prediction-history save policy (keep-latest):** one row per (project, predicted month); pending rows refreshed in place; no row once the predicted month is fulfilled; no history when current progress ≥ 100. Purge only on a **full** progress wipe (partial deletes never prune).
6. **Dataset normalization:** one representative point per calendar month (latest inspection wins).
7. **Polynomial is only for real data.** Regression always fits the project's own real points only; class data enters via blending/fallback, never as fabricated regression points.

### 2.8 Known-good fixture — "Project 7" (DO NOT CHANGE)

Source: `tests/Helpers/CreatesProjectWithHistory.php` → `createProjectWithKnownDataset()`.

- 10 observations (x = month 1..10, y = cumulative %): `[1.00, 2.00, 4.00, 7.00, 12.00, 18.00, 25.00, 33.00, 39.00, 45.00]`
- SLR: `slope = 5.1151515151515`, `intercept = −9.9333333333333`, `R² = 0.95445434179074`, `equation = "y = 5.1152x - 9.9333"`, fit **Excellent**.
- Pipeline: current month_index 10, progress 45.0. Polynomial selected (upward-curving: poly R² 0.9991 vs SLR 0.9584); poly coefficients ≈ `a=-0.0612, b=1.4073, c=-3.8193, d=3.9333`. Next-month prediction **50.77**; band **48.77–52.77**, margin **2.0**.
- Generic helper `createProjectWithHistory()` mirrors it: `progress = 5.2·month − 10 ± noise` → slope ≈5.2, R² ≈0.95.
- `AiSolutionFactory` hardcodes the same snapshot (`slope 5.1152`, `r_squared 0.9545`).

### 2.9 Test map

| Location | Covers |
|---|---|
| `tests/Helpers/CreatesProjectWithHistory.php` | Trait building datasets (see above) |
| `tests/Unit/Services/AI/Regression/RegressionDatasetBuilderTest.php` | Monthly normalization |
| `tests/Unit/Services/AI/Regression/LinearRegressionServiceTest.php` | Datasets A–D, fit bands, errors |
| `tests/Unit/Services/AI/AIPredictionServiceTest.php` | Report sections, Project 7 pipeline |
| `tests/Unit/Services/AI/AIPredictionPresenterTest.php` | Presenter branches (completed/insufficient/normal) |
| `tests/Unit/Services/AI/ForecastServiceTest.php`, `ProjectAssessmentServiceTest.php`, `BaselineComparisonServiceTest.php` | Forecast/assessment/compare |
| `tests/Unit/Services/AI/CompletionEstimationServiceTest.php`, `ForecastReliabilityServiceTest.php` | Solvers + reliability |
| `tests/Unit/Services/AI/RecommendationB6RuleTest.php`, `ClassStandingTest.php` | B5/B6 |
| `tests/Unit/Services/AI/AIExplanationServiceTest.php` | Gemini prompt content |
| `tests/Feature/Services/AI/ProgressedMonthActivityGuardTest.php`, `CrossProjectSupplementIntegrationTest.php` | Integration |

Run: `php artisan test` (full suite) or `php artisan test --filter=<Class>`. Tests use SQLite `:memory:` (see phpunit.xml).

---

## 3. What is DONE (feature inventory)

Ordered oldest → newest:

1. **V1 module** (all phases DONE): SLR, dataset builder, forecast, baseline comparison, assessment, presenter, recommendation engine, Gemini integration, Blade UI. Docs: `textfiles/done/ai_module_ver1_plansandlogs/` (master plan `regression_implementation_master_plan_ver5.txt`).
2. **V2 Phase 1 — Polynomial regression** (cubic via Gaussian elimination). ✅ Jul 22 2026.
3. **V2 Phase 2 — Model selection** (SLR vs poly, R² improvement ≥0.02). ✅
4. **V2 Phase 3 / 3.5 — Completion estimation** (schedule-aware primary + Newton–Raphson best-case + EAC_time; Newton-Raphson later future-guarded by Phase 14 Fix H). ✅
5. **V2 Phase 5 — Dual S-curve** (shifted schedule-aware overlay, NOT polynomial line — see `others(notes)/polynomial_over_optimism_findings.txt`). ✅
6. **V2 Phase 6 — Estimated Completion Gauge.** ✅
7. **V2 Phase 7 — Prediction History** (keep-latest; fixes in `prediction_history_fix_plan.md`). ✅
8. **V2 Phase 8 — Cross-Project Historical Regression** (`HistoricalSimilarityService`). ✅ Jul 28 2026. Follow-up health-gauge fixes (`phase_8_health_gauge_fix_plan.txt`).
9. **V2.5 Safety Valves** (MIN_SIMILAR_PROJECTS=3, scale-ratio floor 0.5, MIN_SIMILARITY_SCORE=50, similarity audit details). ✅ `cross_project_safety_valves_plan.md`.
10. **V2 Phase 14 — Forecast Reliability & Prediction Confidence Rebase** (weighted 0–100 score; next-month band; R² relabeled; Fix H folded in). ✅ merged @ `a45a156`.
11. **V2.6 Phase 1 — Honest fixes** (X-axis merge, pool gate, null-safe scoring, honest fit wording, A4 0-obs category baseline). ✅
12. **C7 — Accuracy-by-data_source logging** (dev backtest at `/dev/prediction-accuracy`). ✅ Aug 13 2026.
13. **V2.6 Phase 2 — Reference-class blend** (`w = obs/(obs+4)`, applies to ALL projects, P25/P75 range, reliability shrink). ✅ merged @ `a0f301d`.
14. **A2 — Completion sanity fallback** (class-derived estimate when polynomial is implausible). ✅ `completion_sanity_fallback_plan.md`.
15. **B5/B6 — Percentile benchmark + at-risk flag** (banded percentile tiers + 2-consecutive-months-below-P25 at-risk). ✅ `percentile_benchmark_at_risk_plan.md`, `b56_defense_notes.txt`.

**Regression-module tail is CLOSED (as of Aug 14, 2026).** Full suite at the end of the tail: 656 tests / 2485 assertions.

---

## 4. NEXT / FUTURE WORK (ordered)

Per `textfiles/summary_of_next_work.txt` (keep this priority order):

1. **Core Monitoring Phase E — AI ↔ baseline linkage** (PREP ONLY, ⬜ NOT STARTED).
   Plan: `textfiles/core_monitoring_completion_plan.md`. Scope: add `baseline_schedule_id` to `ai_solutions` (= current pending baseline at generation time). No snapshot/outcome display yet — that waits for Phase 10. This is the enabler for the future recommendation feedback loop.
2. **AI Phase 9 → 10 — Contractor Performance Tracking → AI-Powered Recommendations** (highest capstone demonstration value).
   - Phase 9: build contractor metrics — `performance_rating` (1–5), `on_time_delivery_pct`, `completed_projects_count`, `avg_delay_days`, `total_extensions_count`, `total_extension_days`. Extension stats are informational only (no scoring impact). Branch: `ai/phase9-contractor-performance`. Plan: `textfiles/contractor_performance_tracking_plan.md`. **Status: ✅ DONE (incl. extension stats).**
   - Phase 10: AI-generated recommendations. Plan: `textfiles/ai_recommendation_plan.md` (updated Aug 25 with industry research). AI-first with rule-based fallback. Gemini prompt includes contractor performance, issue breakdown, extension patterns, cross-project benchmarks. Same output format, no UI changes. **Status: 📋 PLAN READY (awaiting approval).**
   - After: Phases 11 (export/reporting — note Excel report exists but is frozen pending corruption fix, see `report_generation_plan.md` §6.4), 12 (architectural: caching TTL 1h, background jobs, `config/ai.php` threshold migration), 13 (testing ~150 more).
3. **Pending (small):** Extension approval activity logging — `Admin/ProjectExtensionController` `store()`/`update()` lack `ActivityLog::create()` (ref: `textfiles/uncertain/baseline_extension_adjustment_plan__4_ (2).txt` Part A).
4. **Deferred / low priority (safe to skip):** MLR (issue density, contractor experience, seasonality, extension count; budget utilization excluded by client), Project Comparison, Bulk Operations, User Activity Summary, Project Templates, Data Export/Import, Job Queue utilization, Dead Code Cleanup, Activity Log UI for Staff, completion-date interval UI (A3), k-backtest tuning via C7, `config/ai.php` migration (Phase 12).

**Intentionally excluded by client:** Budget burn-rate tracking, Baseline approval workflow, Contractor self-reporting, Formal REST API, S-Curve polynomial overlay.

**Known dead code / cleanup note:** `GET /admin/projects/{id}/alternative` route → `ProjectController::showAlternative` exists in `routes/web.php` but the controller method does NOT exist (would 500). Safe to remove.

---

## 5. Documentation reference index

Legend: ✅ DONE (record/archive) · 📌 ACTIVE/REFERENCE (read for context) · ⏭️ NEXT-WORK · ⚠️ UNCERTAIN

### Active reference (start here after this file)
| File | Topic | Status |
|---|---|---|
| `textfiles/summary_of_next_work.txt` | Master next-work list; full detail for §4 above | ⏭️ UPDATE as you go |
| `textfiles/active_reference/ai_module_ver2_master_plan.txt` | V2 authoritative scope (phases 1–14 + addendum) | 📌 |
| `textfiles/active_reference/ai_v2_phase1_progress_log.txt` | Phase 1 (poly) + Phase 3 (completion) records | ✅/📌 |
| `textfiles/active_reference/ai_v2_phase2_blend_progress_log.txt` | v2.6 blend record (merged a0f301d) | ✅/📌 |
| `textfiles/active_reference/ai_v2_phase8_cross_project_progress_log.txt` | Phase 8 record + defense Q&A | ✅/📌 |
| `textfiles/active_reference/ai_v2_phase14_forecast_reliability_progress_log.txt` | Phase 14 record (merged a45a156) | ✅/📌 |
| `textfiles/active_reference/phase8_cross_project_regression_plan.txt` | Original Phase 8 plan | ✅/📌 |
| `textfiles/active_reference/phase_8_health_gauge_fix_plan.txt` | Post-Phase-8 health gauge/completion fixes (merged 5d8dfc3) | ✅/📌 |
| `textfiles/active_reference/project_context_and_development_rules.txt` | Project-wide dev rules/context | 📌 |

### Plans (mostly DONE; read for decisions & numbers)
| File | Topic | Status |
|---|---|---|
| `textfiles/regression_v2_5_cross_project_expansion_plan.md` | A1–A4/B5/B6/C7 option analysis | ✅ (A2/A4/B5/B6/C7 done; A1→v2.6; A3→Phase 14) |
| `textfiles/regression_v2_6_cross_project_prior_flow_plan.md` | v2.6 blend design + decision log D1–D8 | ✅ |
| `textfiles/phase2_blend_reference_class_plan.md` | Plain-English blend summary (weight table, data_source table) | ✅ |
| `textfiles/forecast_reliability_confidence_plan.md` | Phase 14 detailed design (weights, floors, decisions) | ✅ |
| `textfiles/percentile_benchmark_at_risk_plan.md` | B5/B6 design | ✅ |
| `textfiles/completion_sanity_fallback_plan.md` | A2 class fallback | ✅ |
| `textfiles/cross_project_safety_valves_plan.md` | v2.5 hardening steps 1–5 | ✅ |
| `textfiles/prediction_history_fix_plan.md` | Phase 7 history fixes (keep-latest) | ✅ |
| `textfiles/core_monitoring_completion_plan.md` | Core monitoring phases A–E | ⏭️ **Phase E NOT STARTED** |
| `textfiles/report_generation_plan.md` | Reporting (Excel frozen pending corruption fix §6.4) | ✅ (mostly) |
| `textfiles/ai_recommendation_data_gaps.txt` | What data AI recs need (Phase 10 groundwork) | 📌 |

### Logs / notes
| File | Topic | Status |
|---|---|---|
| `textfiles/fixes_and_improvements_log.txt` | Numbered fixes 0–14 (incl. month_number semantics #14) | ✅/📌 |
| `textfiles/gaps_and_features_missing.txt` | Feature gap catalog with legend | 📌 |
| `textfiles/b56_defense_notes.txt` | Defense notes for B5/B6 | 📌 |
| `textfiles/others(notes)/polynomial_over_optimism_findings.txt` | Why polynomial alone is over-optimistic (Project 138) | 📌 |
| `textfiles/others(notes)/completion_estimation_explanation.txt` | EVM/EAC_time theory + defense statements + ML feasibility | 📌 |
| `textfiles/completion_calculation_demo_project_138.txt` | Step-by-step completion calc demo (Project 138) | 📌 ⚠️ **duplicate** — identical copy also in `textfiles/others(notes/` (MD5-equal); consolidate when reorganizing |
| `textfiles/uncertain/*.txt` | Verified done in code (can move to done/) per summary §7 | ⚠️ |

### Archived (done) docs
- `textfiles/done/ai_module_ver2_planandlogs/` — Phase 1, 2, 3, 3.5, 5, 7 plans & progress logs.
- `textfiles/done/ai_module_ver1_plansandlogs/` — V1 master plan (ver5), progress logs, phase 5/6/7 plans.

---

## 6. Quick verification checklist after any AI-module change

1. Run the AI unit suite: `php artisan test --filter=Services/AI` and the integration tests listed in §2.9.
2. Confirm "Project 7" expectations unchanged (slope 5.1152, R² 0.9545, next-month 50.77, band 48.77–52.77).
3. If you touch month indexing, re-run `fixes_and_improvements_log.txt` item 14 regression test (T9).
4. If you touch prediction persistence, re-read §2.7 rules 5 (keep-latest) and confirm against `prediction_history_fix_plan.md`.
5. If you add a new `data_source`, add its disclaimer wording to the presenter AND the explanation prompt, and extend C7 backtest grouping.
6. UI assertions live in the `ai-prediction.blade.php`/`overview.blade.php` component tests — grep tests for `ai_prediction` if your change is presentation-only.

---

*This file lives at `textfiles/active_reference/AI_MODULE_HANDOVER.md`. Keep it updated as the module evolves; the teammate (or next AI session) will start here.*
# V2 PHASE 14 — FORECAST RELIABILITY & PREDICTION CONFIDENCE REBASE

CSPC PMU System — AI Module Version 2 follow-up

Status: 🔄 IN PROGRESS — Steps 0-2 COMPLETE and committed (a13037d Step 0,
        <step1> Step 1, <step2> Step 2). Test counts: Step 0 565, Step 1 569,
        Step 2 591.
        Next: Step 3 — AIPredictionService integration.
        Commit cadence (revised Aug 11, 2026, per user): log, test, commit
        AND push after EACH step — steps no longer wait for review before
        committing; review happens at the end (Step 9).
Date: August 11, 2026
Branch (for implementation): `phase-14-forecast-reliability` (from main)
Plan file: textfiles/forecast_reliability_confidence_plan.md (this file)

Reference Documents:
- textfiles/active_reference/ai_module_ver2_master_plan.txt — V2 master plan (Phase 7 §R² misuse)
- textfiles/prediction_history_fix_plan.md — Prediction History (the backtest data source)
- textfiles/others(notes)/completion_estimation_explanation.txt — EVM/EAC defense notes
- textfiles/others(notes)/polynomial_over_optimism_findings.txt — why R² fit != future accuracy
- textfiles/active_reference/ai_v2_phase8_cross_project_progress_log.txt — Phase 8 (reference-class data)
- textfiles/gaps_and_features_missing.txt — items 2 & 6 (cross-project, forecast table)

===============================================================================
  1. PROBLEM STATEMENT
===============================================================================

The AI Prediction tab presents R² (coefficient of determination) as a
prediction-confidence signal:

  • `completion_confidence` (High/Medium/Low) is driven mainly by R²
    (CompletionEstimationService::classifyConfidence()).
  • `certainty_label` ("Good data, strong fit") blends R² fit with
    observation count.
  • R² is shown as "fit quality" / "R²: 0.95 — Excellent" on the UI and in
    the Gemini prompt.

R² only measures how well the fitted curve matches the PAST data points.
It does NOT measure whether the next prediction will be correct:

  • A cubic polynomial can fit 4 points almost perfectly (R² ~ 0.99) yet
    miss the 5th month badly — proven in-house by
    polynomial_over_optimism_findings.txt (Project 138: polynomial R² 0.9989
    predicted the project 3 days AHEAD while it was behind every month).
  • The forecasting literature is unambiguous: fit is NOT prediction
    accuracy. Out-of-sample performance is the only honest measure
    (Hyndman; Armstrong; STAT 462; PLOS ONE "r and r² are incorrect
    measures of predictive accuracy").
  • The V2 master plan itself already documented this ("R² misused as
    prediction confidence") and Phase 7 built prediction_history as the
    REAL accuracy signal — but the confidence wording was never rebased
    onto it.

A panel/capstone defense question is highly likely:
  "Why do you call R² 'confidence'? That only measures curve fit."

===============================================================================
  2. OBJECTIVE
===============================================================================

  • Replace R²-based "prediction confidence" wording with a genuine,
    explainable FORECAST RELIABILITY signal built from real evidence.
  • Keep R² on screen but RELABEL it as "Curve Fit Quality (matches past
    data only)" — it remains a legitimate model-selection and fit diagnostic.
  • Give the PMU user a single trust indicator per forecast with an
    explicit "why" (factor breakdown) so the number is auditable.
  • Stay backward compatible: keep existing report/presenter keys, add new
    keys, update Blade wording.

===============================================================================
  3. RESEARCH BASIS (what similar systems actually trust)
===============================================================================

  Real forecasting/ML systems do NOT use R² to claim confidence. They use:

  • Backtesting / holdout accuracy — "if the model had predicted the last
    N months, how wrong was it?" (Amazon Forecast: RMSE/MAPE/wQL computed on
    held-out windows; DataRobot: backtest + holdout; Recast: median holdout
    accuracy; sktime: expanding-window backtesting).
    → Our existing prediction_history table IS this. Strongest signal.

  • Data sufficiency / maturity — EVM research (4,100 simulated projects,
    MDPI 2023) shows forecasts become reliable only as a project passes
    ~50% completion; early forecasts are inherently low-trust regardless of
    the curve fit. → Use REAL observation count (excluding synthetic points).

  • Forecast horizon — accuracy decays with distance (DataRobot plots
    accuracy per forecast distance). Next-month is most reliable; a
    completion many months away is less so.

  • Range / interval instead of a single point — AACE RP 104R-19 (estimating
    industry standard): never present one point estimate; present a range
    with expected accuracy. → Our completion_multi spread (earliest/latest/
    agreement across 3 methods) is already a ready-made interval.

  • Cross-method agreement — when Schedule-Aware, Polynomial and EAC_time
    agree closely, trust rises; when they diverge, trust falls.

  • Reference-class evidence — Kahneman's Reference Class Forecasting:
    base expectations on the actual outcomes of comparable completed
    projects. → Our HistoricalSimilarityService (Phase 8) provides exactly
    this, currently underused.

  • RANGE FOR THE NEXT-MONTH PREDICTION TOO — "never present a single point
    estimate" applies to the next-month % as much as to the completion date.
    Today next_prediction.progress is one number; it must become a band
    (point ± interval built from the model's residual error / backtest
    spread), and prediction_history must store that band so coverage can be
    scored later.

===============================================================================
  3.5 LIVE REPRODUCTION — PROJECT 142 (Aug 11, 2026)
===============================================================================

  Reproduced the exact failure mode the user saw. Project 142:
    start ~Apr 2026, original end Mar 31 2027, current end Apr 30 2027;
    month 9 (Dec 2026) planned 85 / actual 65.
    9 real observations: y = [2,3,7,15,24,35,45,54,65]

  Numbers from the live pipeline (data_source=project_history, no supplement):
    SLR R² 0.9707 vs Polynomial R² 0.9993  → polynomial selected
    Poly coefs: a = -0.125421, b = 2.453824, c = -5.301708, d = 4.801587
    schedule_aware   → 2027-05-25 (index 14.33)
    earned_schedule  → 2027-06-23 (index 15.26)
    POLYNOMIAL       → 2026-04-22 (index **-4.7825**) ← IN THE PAST
    comparison: agreement=Low, spread=427, valid_count=3

  Root cause (confirmed in code):
    estimatePolynomial() is the ONLY one of the three completion methods
    WITHOUT a future guard. estimate() (CompletionEstimationService.php:65)
    and estimateEarnedSchedule() (:255) both return null when the solved
    index <= currentMonthIndex; estimatePolynomial() does not. A cubic with
    a < 0 bends back down after its peak, so Newton-Raphson (seeded at
    totalPlannedMonths+1) walks to a spurious low root (-4.78); indexToDate()
    then clamps onto baseline[0] ≈ Apr 2026.

  Consequences for this plan:
    • The agreement factor (15%) is structurally broken TODAY: it compares
      all three methods, and polynomial's biased/early/past output drags
      agreement to Low even when the two schedule-anchored methods are only
      29 days apart (that pair alone = Moderate).
    • Same story as Project 138 in polynomial_over_optimism_findings.txt:
      near-perfect in-sample fit (0.9993) that means nothing about the
      forecast. Section 11 Decision 5 records the fix-then-remove policy.

===============================================================================
  4. CHOSEN DIRECTION
===============================================================================

  Introduce a single new service, ForecastReliabilityService, that computes
  a weighted, explainable RELIABILITY SCORE (0–100) and level
  (High / Medium / Low) for each forecast, based on four evidence factors
  plus hard honesty floors. R² is demoted to:
    • a model-selection input (unchanged, SLR_POLYNOMIAL_R2_THRESHOLD),
    • a fit floor (R² < 0.50 → Low regardless of other evidence),
    • a "Curve Fit Quality" label only (never "confidence").

  Where reliability is computed:
    a) Next-Month prediction  → the headline forecast card.
    b) Completion estimate    → the "Estimated Completion" card (replaces
       the R²-driven completion_confidence as the trust signal; the three-
       method comparison keeps its agreement badge).

  Additional scope adopted Aug 11, 2026 (from the Project 142 test, §3.5):
    c) Next-month prediction becomes a RANGE (point ± interval), never a
       bare single %. Interval built from residual/backtest error; stored
       and backtested alongside the point.
    d) prediction_history gains nullable predicted_low / predicted_high so
       the backtest factor can measure COVERAGE, not just point distance.
    e) Agreement is repaired first (polynomial future-guard fix); if the
       polynomial estimator still cannot produce sound future dates, it is
       REMOVED from the multi-method comparison (the S-curve overlay
       polynomial — a fit diagnostic — stays). See §5 Agreement + Decision 5.

===============================================================================
  5. SCORING DESIGN
===============================================================================

  NEW:  app/Services/AI/ForecastReliabilityService.php
  (pure math/service, no DB queries — inputs are passed in; follows SRP and
  the module's Regression/ style. Thresholds as class constants for now;
  flagged for config/ai.php in Phase 12.)

  Public API
  ----------
    computeForNextPrediction(array $context): array
    computeForCompletion(array $context): array

  Both return the same shape:
    [
      'level'   => 'High' | 'Medium' | 'Low',
      'score'   => int (0-100),
      'floors'  => [ 'blocked' => bool, 'reason' => string|null ],
      'factors' => [
        'backtest'  => [ 'score' => int, 'detail' => string ],
        'data'      => [ 'score' => int, 'detail' => string ],
        'horizon'   => [ 'score' => int, 'detail' => string ],
        'agreement' => [ 'score' => int, 'detail' => string ],  // completion only
      ],
    ]

  Weights
  -------
    W_BACKTEST  = 40   (real predicted-vs-actual record — strongest)
    W_DATA      = 30   (real observation count, synthetic excluded)
    W_HORIZON   = 15   (months ahead)
    W_AGREEMENT = 15   (completion methods spread; next-month uses neutral)

  Factor scoring
  --------------
  Backtest (from prediction_history rows that have actual_next):
    • fulfilled >= 3:
        accurate = count(|variance| < 2%), close = count(|variance| < 5%)
        score = min(100, accurate/fulfilled * 100 + close/fulfilled * 50)
        detail = "6 of 9 predicted months accurate (avg error 2.1%)"
    • fulfilled in [1, 2]:
        score = max(0, 100 - avgVariance * 10)
    • fulfilled == 0:
        score = 60 (neutral — no evidence yet, not penalized hard)
        detail = "No prediction history yet"

  Data (real observation count = observation_count − synthetic_points_count):
    >= 8 → 100; >= 6 → 85; >= 4 → 70; >= 2 → 40; else → 20

  Horizon:
    • next-prediction: always 1 month → 100
    • completion: monthsToCompletion = estimated_index − current_index
        <= 3 → 100; <= 6 → 85; <= 12 → 65; <= 24 → 45; else → 25

  Agreement (completion only; valid_count >= 2 required to trust it):
    High → 100; Moderate → 70; Low → 40; N/A → 60; valid_count < 2 → 60

    AGREEMENT POLICY (Decided Aug 11, 2026 — §3.5 + Decision 5):
      1. FIX THE POLYNOMIAL FIRST. estimatePolynomial() gains the same
         future-guard the other two methods have (root <= currentMonthIndex
         → null) plus an a < 0 descending-branch rejection (curve cannot be
         extrapolated to 100%). Agreement is then still computed across all
         three REVISED methods (spread <= 15 High / <= 45 Moderate).
      2. If, across the live projects, polynomial estimates remain
         unsound/frequently early after the guard fix, REMOVE polynomial
         from this comparison and compute agreement/spread over
         schedule_aware + earned_schedule only (the two schedule-anchored
         methods — the pair already agrees closely in reality). Polynomial
         may keep a separate "optimistic bound" line or be dropped from the
         card entirely; the polynomial S-CURVE OVERLAY LINE IS UNTOUCHED
         (it is a legitimate fit visualization, per Phase 5).
      3. The agreement factor detail string must say which methods were
         compared (e.g. "All 3 methods" vs "Schedule-aware + EAC_time").

  Prediction interval / range (NEW with this phase)
  ------------------------------------------------
  Next-month prediction is reported as a band, never a single point:
    next_prediction.progress      = point estimate (kept keys, unchanged)
    next_prediction.progress_low  = point − margin   (new)
    next_prediction.progress_high = point + margin   (new)

    margin = max( fitResidualRMSE, backtestAvgAbsError, 2.0 )   [%]
    • fitResidualRMSE: std of model residuals over observed months from the
      selected regression analysis (pure math on the already-computed fit).
    • backtestAvgAbsError: mean |actual − predicted| over fulfilled
      prediction_history rows (0 when none).
    • floor 2.0% so short/new projects never show an impossibly tiny band.
    Clamp low at >= 0 and high at <= 100.

    prediction_history gains nullable columns predicted_low / predicted_high
    (same keep-latest rows; backfilled with the band on save). The backtest
    factor then scores BOTH point accuracy AND coverage (% of fulfilled
    months whose actual fell inside the band — narrow band that catches the
    actual is the ideal).

  Hard floors (any trigger → level 'Low', no further scoring needed):
    • R² < 0.50                                → "Model fit too weak"
    • next-prediction: realObs < 2              → "Too little data"
    • completion: estimate is null              → no reliability (caller N/A)

  Honesty cap:
    High requires (fulfilled >= 3) OR (realObs >= 6).
    Prevents a sparse project from looking reliable just because the curve
    happens to fit well — the exact failure mode being fixed.

  Level thresholds:
    weighted score >= 80 → High; >= 55 → Medium; else → Low.

===============================================================================
  6. INTEGRATION (data flow)
===============================================================================

  MODIFIED: app/Services/AI/AIPredictionService.php
    • Add ForecastReliabilityService as a required constructor dependency
      (own dependencies: none — Laravel auto-resolves).
    • In generatePrediction(), after completion_multi is built, assemble the
      reliability context and compute next_prediction_reliability
      (horizon = 1; agreement neutral) and completion_reliability
      (horizon from completion index; agreement from completion_multi),
      or null when completion is null.
    • buildPredictionReport() adds a 'reliability' block:
        'reliability' => [
          'next_prediction'   => [...score shape...],
          'completion'        => [...score shape... or null],
          'fit_quality_label' => 'Curve Fit Quality',
        ]
    • next_prediction also gains progress_low / progress_high (the interval
      band from §5); savePredictionHistory() writes them to the new
      prediction_history columns.

  MODIFIED: app/Services/AI/CompletionEstimationService.php (Step 0, §7)
    • estimatePolynomial(): add the future-guard the other two methods have
      — if the solved root <= currentMonthIndex → return null (never a past
      completion date). Add an a < 0 descending-branch rejection so a
      peak-then-bend-down cubic is not extrapolated to 100% at all.
    • Matching tests in tests/Unit/Services/AI/CompletionEstimationServiceTest.

  MODIFIED (schema): database/migrations/
    • NEW migration adding nullable predicted_low / predicted_high
      (decimal 5,2) to prediction_history. Existing rows stay valid (nulls);
      backtest handles old rows as point-only until re-saved.
    • buildMinimalReport() adds an empty/neutral reliability block so all
      existing report keys stay present (backward compatible).

  MODIFIED: app/Services/AI/AIPredictionPresenter.php
    • Expose from the report: forecast_reliability (pass-through),
      next_prediction_reliability, completion_reliability, and a flattened
      factors list for the "why" tooltip.
    • KEEP existing keys (completion_confidence, certainty_label,
      regression_info) unchanged for backward compatibility — Blade simply
      stops presenting R² as confidence.
    • regression_info keeps r_squared + fit_quality; Presenter adds a
      display label 'fit_quality_label' = 'Curve Fit Quality'.

  MODIFIED: resources/views/.../tabs/ai-prediction.blade.php
    • Relabel the section "Prediction Confidence" → "Forecast Reliability".
    • Estimated Completion card: show reliability badge (green/yellow/red)
      as the headline instead of the R²-based confidence badge.
    • Next-Month card: small reliability badge + "why" tooltip listing the
      factor breakdown (backtest / data / horizon).
    • Model Summary box: relabel "R² / Fit Quality" heading to
      "Curve Fit Quality (matches past data only)".
    • Three-method comparison card: keep agreement badge; add per-method
      reliability where available.

  MODIFIED: app/Services/AI/AIExplanationService.php
    • buildPrompt(): replace the raw "R²: 0.95 (Excellent fit)" line with a
      RELIABILITY line from the report:
        "Forecast reliability: Medium (62/100). Prediction history:
         Accurate 6/9 (avg error 2.1%). Data: 6 real observations.
         Horizon: 8 months."
    • R² may remain as a secondary note, never as the trust statement.

  MODIFIED: public/css/... (reliability badge styles) + optional small JS
    tooltip. Reuse existing badge classes (green/yellow/red pills).

===============================================================================
  7. IMPLEMENTATION STEPS (ordered; test each before proceeding — Rule 8)
===============================================================================

  Step 0 — Polynomial completion estimator fix + Fix H clamps (pre-req, §3.5)
    SCOPE DECISION (Aug 11, 2026): Fix H (non-monotonic polynomial) is folded
    into Step 0 — the completion estimate AND the next-month/forecast month
    predictions are repaired together, because they share one root cause (the
    selected cubic can bend downward beyond the data). Full Phase 14 is the
    scope of this branch.
    (1) MODIFIED app/Services/AI/CompletionEstimationService.php
          estimatePolynomial(): future-guard (root <= currentMonthIndex → null)
          + a < 0 descending-branch rejection.
          Signature gains int $currentMonthIndex (CompletionComparisonService
          passes it through).
    (2) MODIFIED app/Services/AI/AIPredictionService.php  predictNextMonth()
          Replace the bare 0-100 clamp with a floor at the current/latest
          actual progress: return max(latestActual, min(100, predicted)).
          (Fix H item 1 — next prediction must never drop below actual.)
    (3) MODIFIED app/Services/AI/ForecastService.php  generateFutureForecast()
          Enforce a NON-DECREASING forecast: each predicted month = max(prev,
          clamp(predicted)), seeded with the current actual progress, so the
          forecast line never bends down month-over-month. (Fix H item 2.)
    • Keep the S-curve overlay polynomial untouched (fit visualization).
    • NEW/updated unit tests: CompletionEstimationServiceTest (root in the
      past → null; a < 0 peak-then-descend → null; sanity), AIPredictionServiceTest
      (next-month floor), ForecastServiceTest (monotonic forecast).
    • Verify: re-run estimateAll on project 142 — polynomial must now be null
      (or a sane future date), and agreement between schedule-aware +
      earned_schedule = Moderate (was Low/427d due to the past date).

  Step 0 — DONE ✓ (Aug 11, 2026) — commit <pending review>
    All four code changes implemented and tested. Nothing merged yet (Rule 12).
    Actual results:
    • CompletionEstimationService::estimatePolynomial() — new param
      int $currentMonthIndex; future-guard (root <= currentMonthIndex → null)
      + a < 0 descending-branch rejection (f'(root) < 0 → null).
    • CompletionComparisonService::estimateAll() passes currentMonthIndex
      through (CompletionComparisonService.php:36).
    • AIPredictionService::predictNextMonth() — floor at latest actual:
      max(latestActual, min(100, predicted)); call site passes $currentProgress
      (computed once at AIPredictionService.php:252, duplicate removed).
    • ForecastService::generateFutureForecast() — new optional param
      float $latestActual = 0 (backward compatible); forecast months are
      non-decreasing, seeded from the current actual progress.
    Tests added:
    • NEW tests/Unit/Services/AI/CompletionEstimationServiceTest.php (5 cases:
      past-root → null; root-at-current-month → null; a<0 descending-tail →
      null; healthy S-curve still predicts ~2027-04-05; non-polynomial → null).
    • ForecastServiceTest: forecast never decreases month-over-month; first
      forecast month floored at latest actual.
    • AIPredictionServiceTest: next prediction floored at latest actual.
    Live verification (project 142, same pipeline as §3.5):
    • BEFORE: polynomial → 2026-04-22 (index -4.7825, PAST), agreement Low/427d.
    • AFTER:  polynomial → NULL; schedule_aware 2027-05-25 (14.333);
      earned_schedule 2027-06-23 (15.261); agreement MODERATE, spread 29 days.
    Full suite: 565 passed (2021 assertions).

  ==============================================================================
  STEP 0 — SIMPLER TERMS EXPLANATION (for the user/capstone defense)
  ==============================================================================
  The problem: the app estimated when a project would finish three ways. One
  method (polynomial) could return a finish date that was already in the past,
  or a "curve" that went up then down. A project's progress can't go down, so
  those dates were nonsense and made the overall estimate look unreliable.

  The fix (3 small safety rules):
  1. No past dates — if the math says the project finished at or before
     "now" (the latest real progress data), we refuse to print a date.
  2. No shrinking curves — if the fitted curve bends downward after its peak,
     we refuse to extrapolate it at all; it doesn't represent real progress.
  3. No going backwards — the "next month" forecast and every future month are
     never lower than where the project already is (progress only goes up).

  Result on the example project (142): the nonsense past date disappeared,
  and the two trustworthy methods now agree within 29 days (Moderate) instead
  of disagreeing by 427 days (Low). The S-curve chart line is untouched — it
  only visualizes the past, it doesn't predict.

  ==============================================================================
  STEP 1 — SIMPLER TERMS EXPLANATION (for the user/capstone defense)
  ==============================================================================
  The problem: the "Next Month Prediction" box showed ONE exact number, like
  72%. A single number looks certain, but the curve fit is only an estimate —
  the real value could easily be 69 or 75. Estimating standards (AACE RP
  104R-19) say: never present one point; present a RANGE with the expected
  accuracy.

  The fix (Step 1):
  1. The next-month prediction is now a BAND — e.g. "50.8% (48.8% – 52.8%)".
  2. The width of the band is honest: it's the LARGEST of three things:
     • how loosely the fitted curve matches the past months (residual RMSE),
     • how wrong our past predictions were (backtest average error),
     • a minimum of 2% so new projects don't show an impossibly tiny range.
  3. The band never goes below 0% or above 100%.
  4. The band is SAVED into the prediction history table, so later we can
     score whether reality actually landed INSIDE the range we promised
     (coverage) — that becomes part of the reliability score in Step 2.

  Note: the website doesn't show the band yet — Step 1 is the math and the
  storage. The UI shows it in Step 5.

  ==============================================================================
  STEP 2 — SIMPLER TERMS EXPLANATION (for the user/capstone defense)
  ==============================================================================
  The problem: the app called the curve fit (R²) "confidence". But fitting
  the past well doesn't mean the future prediction is right. We need a trust
  score built from REAL evidence, not from a prettiness metric.

  The fix (Step 2): a new score from 0-100 called FORECAST RELIABILITY, made
  of four weighted, auditable parts:
  1. Backtest (40 pts) — how often our past predictions were right. If we
     said "next month 50%" and reality was 50.5%, that's an accurate hit;
     within 5% is "close". If our promised range (Step 1 band) actually
     contained the real number, that counts too.
  2. Data (30 pts) — how many REAL progress records back the forecast
     (more months of actual data = more trustworthy; borrowed synthetic
     points from similar projects don't count).
  3. Horizon (15 pts) — next month is the most reliable; a finish date many
     months away is less so.
  4. Agreement (15 pts) — for completion dates only: when the three
     estimation methods agree within a few days, trust rises.

  Honesty rules:
  • If the curve fits badly (R² < 0.50) or there's almost no data, the score
    is automatically Low no matter what the other parts say.
  • A project can't claim "High" reliability unless we've already seen ≥3
    real predictions come true OR it has ≥6 months of real data.
  • Result: a colored badge (green/yellow/red) with a "why" breakdown, so a
    panelist can read exactly where trust comes from.

  Step 1 — Prediction interval + history range columns
    • NEW migration: nullable predicted_low / predicted_high on
      prediction_history.
    • AIPredictionService predicts next-month band (point ± margin, §5) and
      savePredictionHistory() persists low/high on keep-latest rows.
    • Tests: margin math (RMSE/backtest/floor), clamping, migration runs.

  Step 1 — DONE ✓ (Aug 11, 2026) — commit <step1>
    Implemented and tested; 569 tests green (2045 assertions).
    Actual results:
    • NEW database/migrations/2026_08_11_000001_add_prediction_range_
      to_prediction_history_table.php — nullable predicted_low / predicted_high
      (decimal 5,2) on prediction_history; existing rows stay valid (nulls).
    • app/Models/PredictionHistory.php — predicted_low/predicted_high added to
      fillable + float casts.
    • AIPredictionService:
      - computeNextMonthBand(): margin = max(fitResidualRMSE,
        backtestAvgAbsError, 2.0) [%]; low/high clamped to [0,100].
      - fitResidualRMSE(): RMSE of the selected regression's residuals.
      - backtestAvgAbsError(): mean |actual − predicted| over fulfilled
        prediction_history rows (0 when none).
      - buildPredictionReport(): next_prediction now carries progress_low /
        progress_high / margin (fallback low = max(0, point−2.0),
        high = min(100, point+2.0), margin 2.0 when band is null).
      - savePredictionHistory(): new optional ?array $nextPredictionBand param;
        persists predicted_low/high on create AND on the keep-latest in-place
        refresh (legacy nulls untouched).
    Tests added (AIPredictionServiceTest): report includes band on known
    dataset (next 50.77 → 48.77–52.77, margin 2.0); backtest error widens
    margin (3 fulfilled rows 8% off → 8.0); band clamped to 0-100;
    history persists + keep-latest refresh updates the band.
    Note: PredictionHistory::create in tests must supply regression_method +
    regression_snapshot (NOT NULL columns).
    Frontend NOT yet updated — the band stays backend-only until Step 5
    (Presenter flattens next_prediction to a scalar at AIPredictionPresenter.php:768).

  Step 2 — ForecastReliabilityService + unit tests
    • NEW  app/Services/AI/ForecastReliabilityService.php
    • NEW  tests/Unit/Services/AI/ForecastReliabilityServiceTest.php
    • Cover: factor scoring (all branches), weights, hard floors, honesty
      cap, High/Medium/Low thresholds, both compute methods, null handling,
      plus new coverage-based backtest scoring (actual in band).
    • Pure unit tests — no database.

  Step 2 — DONE ✓ (Aug 11, 2026) — commit <step2>
    Implemented and tested; full suite 591 tests green (2107 assertions),
    +22 new (62 assertions).
    Actual results:
    • NEW app/Services/AI/ForecastReliabilityService.php — pure math service
      (no DB). Weights W_BACKTEST 40 / W_DATA 30 / W_HORIZON 15 /
      W_AGREEMENT 15 as class constants. Returns the §5 shape:
      level / score / floors.blocked+reason / factors (backtest, data,
      horizon, agreement — each score+detail).
    • computeForNextPrediction(): horizon always 1 → 100; agreement factor
      neutral (60, "N/A (next-month forecast)"). Floors: R² < 0.50 → Low;
      realObs < 2 → Low ("Too little data").
    • computeForCompletion(): returns NULL when estimated_index is null
      (guarded N/A — caller handles). Agreement scored from context
      (High 100 / Moderate 70 / Low 40 / N/A 60 / valid_count < 2 → 60).
    • Backtest factor (Decision 7): point accuracy formulas from §5
      (fulfilled ≥3: accurate/close blend clamped to 100; 1-2: max(0,
      100 − avgVariance×10); 0: neutral 60) PLUS coverage — fulfilled rows
      that carry a band are scored on % whose actual landed inside
      predicted_low..predicted_high, blended 60/40 with the point score so a
      narrow-yet-hit band scores best. Legacy point-only rows score on
      accuracy alone (no coverage text).
    • Data factor buckets (real obs): ≥8→100, ≥6→85, ≥4→70, ≥2→40, else→20.
    • Horizon completion buckets: ≤3→100, ≤6→85, ≤12→65, ≤24→45, else→25.
    • Honesty cap: computed High is downgraded to Medium unless
      fulfilled ≥ 3 OR realObs ≥ 6.
    • Level thresholds: ≥80 High, ≥55 Medium, else Low (rounded weighted sum).
    Tests (22 in ForecastReliabilityServiceTest): backtest all branches +
      coverage blend + legacy point-only; data each bucket (via completion
      mode — next-prediction floors realObs<2); horizon next-month always
      100 + each completion bucket; agreement High/Moderate/Low/N/A/<2 valid;
      both floors; null estimate; honesty cap (capped + allowed with 3
      fulfilled); weighted-score boundaries (100 High / 60 Medium / 40 Low);
      result-shape contract.

  Step 3 — AIPredictionService integration
    • Add constructor dependency + reliability block to reports.
    • Update tests/Unit/Services/AI/AIPredictionServiceTest.php constructor.

  Step 4 — AIPredictionPresenter output
    • Add forecast_reliability + label keys; keep old keys.
    • Update AIPredictionPresenterTest.

  Step 5 — Blade relabel + reliability badges + tooltips
    • Update ai-prediction.blade.php; browser-verify (date-simulator widget)
      on a project with 2+ inspected months.

  Step 6 — AIExplanationService prompt update
    • Update buildPrompt() + a test asserting the prompt contains the
      reliability/accuracy stats.

  Step 7 — CSS badge polish + browser verification
    • Badge colors, tooltip, responsive layout; verify insufficient-data,
      completed, SLR, polynomial, and supplement-mode projects.

  Step 8 — Logs & docs (Rule 11)
    • Update this plan's status; add progress log
      (active_reference/ai_v2_phase14_forecast_reliability_progress_log.txt);
      refresh summary_of_next_work.txt.

  Step 9 — Inform for review (Rule 10 + Rule 12)
    • Stop before merging; present branch phase-14-forecast-reliability
      for approval.

===============================================================================
  8. FILES TOUCHED
===============================================================================

  NEW:
    app/Services/AI/ForecastReliabilityService.php
    tests/Unit/Services/AI/ForecastReliabilityServiceTest.php
    database/migrations/2026_08_11_000001_add_prediction_range_to_prediction_history_table.php
    tests/Unit/Services/AI/CompletionEstimationServiceTest.php  (Step 0 — 5 cases)
    textfiles/forecast_reliability_confidence_plan.md        (this plan)
    textfiles/active_reference/ai_v2_phase14_forecast_reliability_progress_log.txt (Step 8)

  MODIFIED:
    app/Services/AI/AIPredictionService.php
    app/Models/PredictionHistory.php                   (Step 1: band columns)
    app/Services/AI/AIPredictionPresenter.php
    app/Services/AI/AIExplanationService.php
    app/Services/AI/CompletionEstimationService.php        (Step 0: future-guard
                                                              + a<0 rejection)
    app/Services/AI/CompletionComparisonService.php        (Step 0: pass currentMonthIndex)
    app/Services/AI/ForecastService.php                    (Step 0: non-decreasing forecast)
    resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php
    public/css/admin/projects/ (reliability badge styles)
    tests/Unit/Services/AI/AIPredictionServiceTest.php     (Step 0: next-month floor)
    tests/Unit/Services/AI/AIPredictionPresenterTest.php
    tests/Unit/Services/AI/ForecastServiceTest.php              (Step 0 monotonic cases)
    textfiles/prediction_history_fix_plan.md                    (range-columns addendum)
    textfiles/summary_of_next_work.txt

  NO CHANGES NEEDED:
    Regression engine (SLR / Polynomial fit math), RegressionDatasetBuilder,
    BaselineComparisonService, ProjectAssessmentService,
    HistoricalSimilarityService, routes.
    NOTE: completion_confidence / certainty_label keys STAY (backward compat)
    but are no longer the headline trust signal; classifyConfidence() itself
    is untouched.

===============================================================================
  9. BACKWARD COMPATIBILITY
===============================================================================

  • All existing report keys are preserved; the new 'reliability' key is
    additive. Old blades/tests that do not read it are unaffected.
  • completion_confidence / certainty_label / regression_info remain in the
    presenter output; only the Blade presentation changes.
  • The three-method completion comparison and schedule-aware estimate are
    untouched (their date math is already conservative and defensible).
  • AIExplanationService prompt text changes are backward compatible (new
    prompt, same response parsing).
  • Step 0 changes observable behavior for previously-sick cases: where
    polynomial used to emit an impossible (past) completion date, it now
    returns null, so completion_multi.valid_count may drop for those
    projects. This is the intended repair — the card should show the two
    schedule-anchored methods (or the "poly removed" fallback) instead of a
    nonsense date. Regression tests asserting the old past-date output are
    UPDATED, not deleted.
  • New prediction_history columns are nullable; pre-existing rows remain
    valid and are treated as point-only by the backtest factors.

===============================================================================
  10. TESTING STRATEGY
===============================================================================

NEW ForecastReliabilityServiceTest (target ~18 tests):
    • Backtest: 0 / 1-2 / 3+ fulfilled; accurate+close math; clamp to 100
    • Data: each realObs bucket (8/6/4/2/<2)
    • Horizon: each bucket + next-month always 100
    • Agreement: High/Moderate/Low/N/A/<2 valid
    • Floors: R² < 0.50 → Low; realObs < 2 next-pred → Low
    • Honesty cap: High requires fulfilled>=3 OR realObs>=6
    • Level thresholds: 80 / 55 boundaries
    • computeForCompletion with null estimate → guarded N/A

NEW interval tests (Step 1):
    • margin = max(RMSE, backtestAvgAbsError, 2.0); clamp 0-100
    • prediction_history rows save predicted_low/high; keep-latest refresh
      updates them; legacy nulls untouched

NEW CompletionEstimationServiceTest cases (Step 0):
    • polynomial root < currentMonthIndex → null (was: past date, e.g.
      project 142 reproduced 2026-04-22)
    • polynomial a < 0 descending branch → null
    • ordinary S-curve polynomial still returns a sane future date

NEW/FIX H test cases (Step 0):
    • AIPredictionServiceTest — next-month prediction never below latest
      actual (predictNextMonth floor clamp)
    • ForecastServiceTest — modeled months are non-decreasing (no downward
      months), seeded from the current actual progress
    • CompletionComparisonServiceTest — estimateAll passes currentMonthIndex

Updated existing tests:
    • AIPredictionServiceTest — new constructor dep + report key presence
    • AIPredictionPresenterTest — forecast_reliability keys present
    • Any test asserting the old past-date polynomial output → update to the
      new null behavior (intended change, not a regression)

Full suite regression: all V1 + V2 tests still pass (no key removal).

===============================================================================
  11. DECISION LOG
===============================================================================

  Decision 1 — Keep R², relabel it (vs. remove it)
    Context: R² is a fit metric, not a confidence metric.
    Options: remove entirely / relabel / hide behind a toggle.
    Decision: RELABEL to "Curve Fit Quality (matches past data only)".
    Rationale: R² is still a legitimate model-selection + fit diagnostic;
    hiding it reduces transparency. Only the CONFIDENCE CLAIM is removed.
    Consequences: Blade + prompt wording change; math untouched.

  Decision 2 — Weighted evidence score vs. pure backtest
    Context: a brand-new project has little/no prediction history, so a
    backtest-only metric would frequently say "no evidence".
    Options: backtest-only / weighted factors / pure rules.
    Decision: WEIGHTED (backtest 40, data 30, horizon 15, agreement 15)
    with hard floors and an honesty cap.
    Rationale: weights reflect "evidence strength"; floors keep it honest
    when evidence contradicts the score; cap prevents unsupported High.
    Consequences: service is fully unit-testable; each factor is shown in
    the UI so the score is auditable.

  Decision 3 — Where reliability lives
    Context: presenter could compute it, but SRP says orchestration in
    AIPredictionService, pure math in a dedicated service.
    Decision: NEW ForecastReliabilityService; AIPredictionService calls it;
    Presenter only formats/passes through.
    Rationale: matches the existing layered dependency flow (Dataset → …
    → Prediction → Presenter); keeps the "why" data next to the forecast.

  Decision 4 — Keep completion_confidence key, change presentation only
    Context: existing blades/tests may read completion_confidence.
    Decision: keep the key; Blade stops using it as the headline.
    Rationale: backward compatibility without dead keys.

  Decision 5 — Fix the polynomial estimator first; remove from comparison
              only if it stays unsound (USER, Aug 11 2026)
    Context: Project 142 reproduced polynomial completion date in the PAST
    (2026-04-22, index -4.78) because estimatePolynomial() — alone among the
    three methods — has no future guard; agreement was dragged to Low (427
    day spread) while the two schedule-anchored methods were 29 days apart.
    Options: drop polynomial immediately / fix it / ignore the spread.
    Decision: FIX FIRST (Step 0: future-guard + a<0 rejection). Re-test
    across live projects; if polynomial results are still not sound, REMOVE
    polynomial from the multi-method comparison (schedule_aware +
    earned_schedule only) — the polynomial S-CURVE OVERLAY LINE stays, since
    it is a fit visualization, not a forecast.
    Rationale: the over-optimism findings already say polynomial extrapolates
    badly beyond the data; the agreement factor must not be the victim of one
    structurally-broken method.
    Consequences: agreement becomes a reliable trust input; the comparison
    card may show 2 methods instead of 3 for affected projects.

  Decision 6 — Next-month prediction becomes a range, never a single point
    Context: "point estimate" research (AACE RP 104R-19 etc.) applies to the
    next-month % too, not only completion dates. A bare "72%" overstates
    certainty and gives nothing to backtest coverage against.
    Decision: report progress_low/progress_high = point ± margin
    (margin = max(RMSE, backtest abs error, 2.0)), and persist the band into
    prediction_history.
    Rationale: honest signal + enables coverage scoring; floor prevents
    impossibly tiny bands on new projects.
    Consequences: new keys + nullable history columns; UI shows the band.

  Decision 7 — Backtest factor scores BOTH point accuracy AND coverage
    Context: with ranges stored, a single "how far off was the point" measure
    under-rewards intervals that actually contain the outcome.
    Decision: fulfilled-row scoring keeps point accuracy AND adds coverage
    (% of fulfilled months whose actual fell inside the stored band);
    narrow-yet-hit bands score best.
    Rationale: matches how forecasting vendors (Amazon/DataRobot) evaluate
    prediction intervals.
    Consequences: richer backtest detail string; legacy point-only rows are
    scored on point accuracy only until re-saved with a band.

  Decision 8 — Fix H folded into Step 0 (USER, Aug 11 2026)
    Context: the Phase-14 Step 0 poly fix (completion) and the inspection-
    refactor Fix H (next-month + forecast can drop below current actual) share
    the same root cause: a non-monotonic cubic tail beyond the data.
    Options: fix them separately / fold together / defer the forecast side.
    Decision: FOLD — Step 0 now (1) guards estimatePolynomial (future-guard +
    a<0 rejection), (2) floors predictNextMonth() at latest actual, and
    (3) makes ForecastService non-decreasing. Full Phase 14 scope confirmed.
    Rationale: one branch, one shared root-cause fix, one verification pass on
    project 142; avoids two branches fighting over the same regression output.
    Consequences: Step 0 touches three services + their tests; progression to
    Step 1 only after the 142 reproduction shows polynomial null/sane + Moderate
    agreement.

===============================================================================
  12. FLAGGED FOR FUTURE
===============================================================================

  • Configurable thresholds → move reliability weights/floors to
    config/ai.php with Phase 12 (Configurable Thresholds).
  • Full prediction-interval UI for the COMPLETION DATE (A3 in
    regression_v2_5_..._plan.md): the next-month range band is now part of
    Phase 14 (Decision 6, §5); extending the same idea to a completion-date
    range (e.g. P25-P75 band) remains future work, with the reliability
    factor breakdown as the lead-in.
  • Once enough prediction history accumulates, the backtest factor
    dominates and the score becomes a true model-accuracy measure — this
    is the intended long-run behavior.
  • Optionally feed reliability into the RecommendationEngine so low-
    reliability forecasts produce softer/differently-worded advice.

===============================================================================
  13. CONVERSATION CONTINUATION GUIDE
===============================================================================

  1. This plan is the successor for all "confidence" wording in the AI
     module. Read it before touching ai-prediction.blade.php or
     AIExplanationService prompt wording.
  2. Read textfiles/prediction_history_fix_plan.md — the backtest factor
     consumes prediction_history rows (one row per project + predicted
     month, keep-latest semantics, actual_next backfilled on progress
     record add/edit/delete).
  3. Implementation branch: phase-14-forecast-reliability (from main).
     Do NOT merge to main without explicit approval (Rule 12).
  4. Implement Steps 0-9 in order; test each step (Rule 8); inform after
     every finished step (Rule 10); record logs on completion (Rule 11).
  5. The R²-as-confidence bug in the live pipeline
     (AIPredictionService.php:245 reads statistics.synthetic_points_count,
     which is never set) is documented in the Phase 8 findings; when you
     compute real observation counts for the reliability DATA factor, read
     synthetic_points_count from the regression report block of the
     prediction (presenter-ready), NOT statistics.*, so confidence and the
     reliability score agree in supplement mode.
  6. READ §3.5 (Project 142 reproduction) and Decision 5 before touching any
     completion-estimation or agreement code: the polynomial estimator is
     the only method without a future-guard; Step 0 repairs it, and
     agreement may drop to the two schedule-anchored methods if polynomial
     remains unsound (S-curve overlay stays).
  7. Step 0 folds in Fix H (Decision 8): the poly future-guard, the
      predictNextMonth() floor clamp, and the non-decreasing ForecastService
      are implemented together. progress_inspection_refactor_plan.md marks
      Fix H as handled here. Steps 0-2 are COMPLETE (see §7 "Step 0 — DONE",
      "Step 1 — DONE", "Step 2 — DONE"); next session starts at Step 3
      (AIPredictionService integration: add ForecastReliabilityService as a
      constructor dependency + reliability block in reports).

===============================================================================
  END OF PHASE 14 PLAN
===============================================================================
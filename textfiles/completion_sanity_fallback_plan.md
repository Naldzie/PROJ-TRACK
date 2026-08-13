# A2 — COMPLETION SANITY FALLBACK (CROSS-PROJECT AGGREGATE)

CSPC PMU System — AI Module. Option A2 of
`textfiles/regression_v2_5_cross_project_expansion_plan.md` (§3 A2, lines 83-91),
implemented together with Fix H item 3 of
`textfiles/progress_inspection_refactor_plan.md` (§3 Fix H, lines 312-315).

Status: 🔵 PLANNED — plan-only commit on an isolated branch. No code yet.
Date: August 13, 2026
Branch (for implementation): `feature/a2-completion-sanity-fallback` (from main)
Plan file: textfiles/completion_sanity_fallback_plan.md (this file)

Reference Documents:
- textfiles/regression_v2_5_cross_project_expansion_plan.md (§3 A2, §5 Fix H relationship)
- textfiles/progress_inspection_refactor_plan.md (§3 Fix H, item 3 + cross-project tie-in)
- textfiles/regression_v2_6_cross_project_prior_flow_plan.md
  (reference-class blend that already feeds buildClassReference)
- app/Services/AI/CompletionEstimationService.php (file being changed)
- app/Services/AI/CompletionComparisonService.php (orchestrator)
- app/Services/AI/HistoricalSimilarityService.php (aggregate source)
- app/Services/AI/AIPredictionService.php (call site — estimateAllCompletions)
- tests/Unit/Services/AI/CompletionEstimationServiceTest.php (guard tests today)

===============================================================================
  1. PROBLEM STATEMENT
===============================================================================

When a project's OWN polynomial model produces an implausible completion,
`CompletionEstimationService::estimatePolynomial()` returns `null` and the
polynomial method silently drops out of `CompletionComparisonService::estimateAll()`.
The method still claims to be "selected" (its R² beat SLR), yet delivers no
completion answer at all.

Implausible = exactly the branches estimatePolynomial() already guards against:

  • Future-guard `CompletionEstimationService.php:163` — root solved at or
    before the current month index → completion date would be in the PAST.
  • Descending-branch rejection `:171-176` — f'(x) < 0 at the root → the cubic
    crosses 100% on a downward tail (non-monotonic cumulative progress).
  • Absurd-horizon guard `:178` — root beyond MAX_REASONABLE_MONTHS (1000).
  • Not selected / low R² — these returns are legitimate, NOT sanity fallbacks.

Observed (2026-08-01, Project 142): polynomial root at index −3.6064 → April
2026 (a past date). The current guard correctly returns null, but the project
is then left with only 2 completion methods (schedule-aware + earned-schedule),
and the comparison spread/agreement column reflects a missing method.

Why it matters: a project whose own curve has gone sideways (the recent
slowdown that over-fits the cubic) is exactly the project that most needs an
answer. "Similar projects took from 92% to 100% about N months" is a usable,
honest estimate. Returning null here forfeits the one cross-project advantage
the aggregate curve provides.

===============================================================================
  2. OBJECTIVE
===============================================================================

  • Keep all existing guards — the sanity fallback REPLACES the naked `null`
    in the three implausible branches (past date, descending tail, absurd
    horizon) with a class-based answer.
  • Estimate "how long similar projects take to go from the project's current
    progress % → 100%" from the aggregate category curve and BLEND/BOUND it
    against the schedule-aware estimate, so the fallback is never wild.
  • Mark the fallback as such in the emitted result (e.g. a
    `fallback_method: 'class_aggregate'` / `synthetic: true` flag) so the UI
    can keep the data-source badge honest.
  • Do NOT touch the not-selected / low-R² returns (those stay null).

===============================================================================
  3. WHERE THE FALLBACK LIVES (DESIGN)
===============================================================================

Current flow:

  AIPredictionService::estimateAllCompletions()            (AIPredictionService.php:430)
    → CompletionComparisonService::estimateAll()           (estimatePolynomial / estimate / estimateEarnedSchedule)
        → CompletionEstimationService::estimatePolynomial()  (returns null on implausible)

The aggregate (median/lower/upper month-aligned progress curves) is already
built by `HistoricalSimilarityService::buildClassReference(Project, $totalPlannedMonths)`
and consumed elsewhere in the prediction pipeline (AIPredictionService.php:617).
The class reference must be AVAILABLE to the completion stage; today it is not.

3.1 Plumbing options (DECISION NEEDED)

  Option A — Pass the class reference into CompletionComparisonService::estimateAll()
      New optional param `?array $classReference`. AIPredictionService builds it
      (buildClassReference) once alongside the dataset and hands it down. Inside
      estimateAll(), if polynomial returned null AND the implausible branch fired
      (not merely "not selected"), compute the class fallback and substitute it.

      Pros: orchestrator-level, one source of truth, comparison always sees 3
            methods. Cons: estimateAll() signature grows; CompletionEstimationService
            stays project-agnostic.

  Option B — Inject HistoricalSimilarityService into CompletionEstimationService
      Pass the Project into the completion methods; the service builds the class
      reference itself when needed.

      Pros: spec's literal "estimatePolynomial() (and friends)". Cons: pulls a
            DB-touching service + Project into a currently pure function; harder
            unit tests; duplicates buildClassReference call sites.

  Recommendation: Option A. It mirrors the existing pattern (the class reference
  is a pipeline-wide artifact, not a completion-service concern) and keeps the
  completion methods pure/testable. Draft plan assumes Option A.

3.2 Class-based completion estimate (new helper)

  Compute from the median class curve (buildClassReference's `['median']` map of
  month-index → progress%):

  1. Find the month index m where class median reaches the project's current
     progress (interpolate between the two surrounding class months).
  2. Class months-to-go = totalPlannedMonths − m   (class projects complete at
     ~100% around their own planned duration).
  3. Class completion index = currentMonthIndex + class months-to-go.
  4. Bound: clamp to [currentMonthIndex + 1, MAX_REASONABLE_MONTHS] and reject
     past dates (same future-guard semantics as the polynomial path).

  5. BLEND with the schedule-aware estimate when present:
       blendedIndex = (classIndex + scheduleAwareIndex) / 2
     else use classIndex alone (it's already bounded).

  6. Emit the same shape as the other methods (estimated_completion_index,
     estimated_completion_date, predicted_delay_days, completion_confidence)
     plus:
       'fallback_method' => 'class_aggregate'
       'blend_partners'  => ['class_aggregate', 'schedule_aware'] (as applied)

===============================================================================
  4. IMPLEMENTATION STEPS
===============================================================================

Step 1 — CompletionEstimationService: standalone class-based estimator
  New public method `estimateFromClassReference(
      array $classReference, int $currentProgress, int $currentMonthIndex,
      int $totalPlannedMonths, ?Carbon $plannedEndDate,
      ?Collection $baselineSchedules)` returning the bounded class completion
  (the math of §3.2 steps 1-4). Pure — no Project/DB. Reuses indexToDate()
  and classifyConfidence().

Step 2 — CompletionComparisonService::estimateAll(): accept + use the fallback
  New optional constructor/param for the class reference. When
  estimatePolynomial() returns null AND the analysis was polynomial-selected
  (regression_method === 'polynomial', R² ≥ MIN_R2_FOR_ESTIMATE), build the
  class fallback (§3.2 step 5 blend with schedule_aware, which is already
  computed) and set it as the polynomial method's substitute, flagged
  `fallback_method`. If no class reference is supplied (older call sites),
  keep current null behavior — backward compatible.

Step 3 — AIPredictionService::estimateAllCompletions(): supply the reference
  Build the class reference once (buildClassReference — already computed for
  the reliability stage at :617) and pass it into CompletionComparisonService.

Step 4 — UI honesty
  ai-prediction section: when a completion method has `fallback_method`, render
  a small "class-derived estimate" note instead of implying it came from the
  project's own trend. (Gauge/spread/agreement logic unchanged — dates already
  bound.)

===============================================================================
  5. TESTS
===============================================================================

  Extend tests/Unit/Services/AI/CompletionEstimationServiceTest.php:

  T1  estimateFromClassReference returns a future completion index when the
      class median crosses the current progress before the planned end.
  T2  Bound: when the class curve is far behind (all months < currentProgress),
      the estimate is clamped to a sane horizon, never a past date, never
      exceeding MAX_REASONABLE_MONTHS.
  T3  Blend: when schedule_aware is provided, the blended index = mean.
  T4  Fallback flag present: `fallback_method === 'class_aggregate'`.
  T5  Existing guards unchanged: past root / descending tail cases STILL return
      null when NO class reference is supplied (backward compat).

  Extend integration coverage (CrossProjectSupplementIntegrationTest or
  AIPredictionService feature test):
  T6  A project whose polynomial root is in the past produces a polynomial
      completion via the class fallback (not null) when the pool is usable.
  T7  comparison 'valid_count' stays 3 for the Project-142-shaped case when the
      class pool exists; 'agreement' reflects the bounded spread.

  Full suite must stay green before merge (per Rule 8 / project convention).

===============================================================================
  6. RELATIONSHIP TO Fix H / A3
===============================================================================

  • Fix H item 1 + 2 (next-month floor, non-decreasing forecast) are the SAME
    non-monotonic root cause; they ship in the same batch so the clamps and the
    completion fallback don't fight (regression_v2_5_plan §5).
  • Fix H item 3 = non-cross-project versions of the same fallback (fall back
    to schedule-aware/SLR). A2 supersedes the "return null" half by using the
    aggregate; where no class pool exists, the schedule-aware submission stands.
  • A3 (prediction interval) is additive display — unaffected; the fallback's
    confidence can reuse classifyConfidence() unchanged.

===============================================================================
  7. VERIFICATION
===============================================================================

  - php artisan test --filter=CompletionEstimationServiceTest
  - php artisan test --filter=CrossProjectSupplementIntegrationTest
  - composer test (full suite)
  - Manual: simulate Project-142-shaped project with Date Simulator, confirm
    completion list shows 3 methods and the polynomial one is marked "class-
    derived estimate".

===============================================================================
  8. DECISION LOG
===============================================================================

  1. Fallback plumbing — OPTION A (pass class reference down), per §3.1.
     CompletionEstimationService stays pure; the aggregate is a pipeline-wide
     artifact already owned by AIPredictionService.
  2. Blend rule — mean(class, schedule_aware) when schedule_aware exists, else
     bounded class alone. Never emit a date ≤ current month, never beyond
     MAX_REASONABLE_MONTHS.
  3. Backward compat — null class reference → existing behavior byte-for-byte.
  4. Honesty flag — fallback answers carry `fallback_method`, surfaced in UI.
# B5 (LITE) / B6 — PERCENTILE BENCHMARK + AT-RISK FLAG

CSPC PMU System — AI Module. Options B5/B6 of
`textfiles/regression_v2_5_cross_project_expansion_plan.md` (§3 B, lines
125-136). The final slice of the regression-module tail (A2 was the previous
slice).

Status: 🔵 PLANNED — plan-only commit on an isolated branch. No code yet.
Date: August 13, 2026
Branch (for implementation): `feature/b56-percentile-at-risk` (from main)
Plan file: textfiles/percentile_benchmark_at_risk_plan.md (this file)

Reference Documents:
- textfiles/regression_v2_5_cross_project_expansion_plan.md (§3 B5/B6)
- textfiles/regression_v2_6_cross_project_prior_flow_plan.md
  (reference-class blend + buildClassReference shape)
- textfiles/completion_sanity_fallback_plan.md (A2 — the previous tail slice,
  same infrastructure)
- app/Services/AI/HistoricalSimilarityService.php (aggregate + class reference)
- app/Services/AI/AIPredictionService.php (prediction report assembly)
- app/Services/AI/AIPredictionPresenter.php (blade mapping)
- app/Services/AI/RecommendationEngine.php (factor extraction + rules)
- resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php
  (health gauge / status card + regression disclaimer)

===============================================================================
  1. PROBLEM STATEMENT
===============================================================================

The cross-project layer (v2.5/v2.6) already builds a similarity-weighted class
curve — median, P25 (`lower`), P75 (`upper`) per planned month
(`HistoricalSimilarityService::buildClassReference`, lines 338-401) — and feeds
it into predictions, forecasts, reliability, and the A2 completion fallback.
BUT the project present data as a NUMBER next to class data, with no plain-
language statement of "where this project sits among similar completed
projects."

Two companion insights are missing:

  B5 — "Your 60% progress is in the ~middle tier of similar projects of this
    type." Currently the health gauge/status card reflects only the
    baseline progress gap (ever-shifting with the calendar), NOT the project's
    standing relative to its peers.

  B6 — "Actual progress has been below the class's slow-25% band for 2+
    consecutive months → behind similar projects of this type." This is a
    genuinely useful at-risk signal (persistent underperformance vs peers) and
    it is NOT currently fed into RecommendationEngine, which today reasons only
    about gap/risk/issues/carryovers.

===============================================================================
  2. OBJECTIVE
===============================================================================

  • B5 (LITE — DECIDED, Aug 13 2026): report a BANDED percentile descriptor
    from the P25/median/P75 class band the pipeline already carries. NO exact
    percentile computation, NO collection of per-candidate curves.
        Rationale (DECIDED): the live pool is typically 3-fewer-than-100
        similar completed projects for a single-site PMU. With pool sizes that
        small, "exact 72nd percentile" is false precision; a tier ("below the
        25th / between 25-50 / between 50-75 / above the 75th") is honest and
        matches the module's statistics-honesty theme (Phase 14, v2.6).
  • B6: flag the project as "behind similar projects of this type" when actual
    progress sits below the class LOWER band (P25) for 2+ consecutive months.
    Feed the flag into RecommendationEngine so it can emit a corrective
    recommendation.
  • Scale down honestly: when no usable class exists (no similar completed
    projects / fewer than MIN_SIMILAR_PROJECTS usable curves), both B5 and B6
    must quietly render nothing (no benchmark, no flag) — never a fabricated
    peer comparison.

===============================================================================
  3. DESIGN
===============================================================================

3.1 B5 — banded percentile descriptor

  Where current progress stands relative to the class band at the CURRENT
  planned-month index:

    actual  vs  class lower[month] (P25) and class upper[month] (P75)

  Tiers (wording for the status card / health gauge):
    actual  ≤ lower  → "in the slowest quarter (<25th) of similar projects"
    lower  < actual  ≤ median → "in the lower-middle band (25-50th percentile)"
    median < actual  ≤ upper → "in the upper-middle band (50-75th percentile)"
    actual  > upper  → "above the 75th percentile of similar projects"

  Boundary note: medians/percentiles carry the same caveat the forecast band
  does — only P25/median/P75 are stored, so tier edges are approximate; the
  label must not claim more precision than the band provides.

  Anchor month: the current month index (latest real observation's index).
  If latest real observation sits after the plan's last month (extended
  projects), clamp to the last planned month index.

3.2 B6 — at-risk flag (below class lower band 2+ consecutive months)

  Walk the project's real progress history (oldest → newest). For each record
  whose month index is within the planned window, read class lower[index]
  (P25). Count the run of CONSECUTIVE trailing records where
  actual < lower. When that run ≥ 2 → at-risk.

  Definition of the run:
    • Compare against the P25 band only (the class's slow quarter), consistent
      with "behind similar projects."
    • Consecutive = adjacent records in month order; a record at/above the band
      breaks the run.
    • Records beyond the plan's last month: use the last available lower value
      (extended projects still comparable, honesty note unchanged).
    • Fewer than 2 records → never at-risk (no signal).

3.3 Wiring (new output, no regression change)

  AIPredictionService:
    • The blend context already carries `reference` (median/lower/upper + count)
      when blending applies. For hybrid/supplement modes the reference is built
      on demand (A2 pattern in estimateAllCompletions). Reuse the same helper
      so B5/B6 have the reference regardless of data_source.
    • New private computation producing, at report time, a compact block:
        "class_standing" => [
            'usable'        => bool,
            'similar_count' => int,
            'tier'          => 'below_p25'|'p25_50'|'p50_75'|'above_p75'|null,
            'tier_label'    => string|null,
            'at_risk'       => bool,
            'at_risk_run'   => int,   // consecutive months below P25 (0 if n/a)
            'lower_band'    => float|null (P25 at current month),
            'median_band'   => float|null,
            'upper_band'    => float|null,
        ]
    • Attach to the prediction report (alongside the existing similar_projects_*
      disclaimer metadata).

  AIPredictionPresenter::presentNormal():
    • Map class_standing into presentable keys (blade-compatible), defaulting
      to null when the block is absent.

  ai-prediction.blade.php:
    • Status card: when class_standing.usable → append a small "vs similar
      projects" line with the tier label (B5).
    • When class_standing.at_risk → amber/red badge "Behind similar projects
      (2+ months below class band)" (B6).
    • Quiet when !usable (no peer pool) — existing layout untouched.

  RecommendationEngine:
    • extractFactors(): read `class_standing.at_risk` (+ run length) from the
      prediction payload into factors (`class_behind`, `class_behind_run`).
    • assessCorrective(): new rule —
        if class_behind && class_behind_run >= 2 →
          category 'corrective', priority ~3-4,
          reason "Progress has been below the lower band of similar projects of
          this type for N consecutive months."
    • MAX_RECOMMENDATIONS cap unchanged; dedup unchanged.

===============================================================================
  4. IMPLEMENTATION STEPS
===============================================================================

Step 1 — HistoricalSimilarityService (optional helper, no new data)
  If useful, expose a small pure helper `classStandingAt(Project​, reference)`
  — but the tier/run logic can also live in AIPredictionService. Preference:
  keep the classification in AIPredictionService (it already asks for the
  reference); HistoricalSimilarityService stays as the data owner. (Decision in
  §8.)

Step 2 — AIPredictionService
  • Ensure the class reference is available for B5/B6 in ALL modes:
    - blended: blendContext['reference'] (existing)
    - hybrid/supplement/project_history without blend: build on demand via
      HistoricalSimilarityService::buildClassReference() (A2 pattern).
  • Compute class_standing block (tier + at-risk run) once per prediction.
  • Attach to buildPredictionReport() output.

Step 3 — AIPredictionPresenter::presentNormal()
  Map class_standing → blade keys with sensible nulls. Update both the normal
  path and, if cheap, keep insufficient-data/completed paths null-safe.

Step 4 — ai-prediction.blade.php
  Status-card tier line + at-risk badge (honest tier wording per §3.1;
  nothing rendered when class_standing.usable is false).

Step 5 — RecommendationEngine
  extractFactors() + new corrective rule consuming the flag. Update the class
  docblock. Ensure generate() still receives the flag (it already gets
  `$prediction` — the block rides along).

===============================================================================
  5. TESTS
===============================================================================

  Unit — new B5B6 class-standing tests (AIPredictionService or a dedicated
  `ClassStandingService` if a service is extracted):
  T1  Tier edges: actual ≤ P25 → below_p25; P25<actual≤Median → p25_50;
      Median<actual≤P75 → p50_75; actual>P75 → above_p75.
  T2  At-risk run: 2+ consecutive records below P25 → at_risk true, run ≥ 2.
  T3  Run broken: record at/above P25 disarms at_risk even if older dips exist.
  T4  No usable class (empty reference) → usable false, no tier, no flag.
  T5  Extended project: record after the plan's last month clamps to the last
      band and can still contribute to the run.

  Integration — CrossProjectSupplementIntegrationTest:
  T6  Blend-mode project surfaces class_standing with a tier and at-risk either
      true/false deterministically (seed the same completed-project pool).
  T7  No-pool project: class_standing.usable = false and no recommendation
      fired from the B6 rule.

  RecommendationEngine (unit or feature):
  T8  class_behind_run >= 2 → a corrective recommendation with the B6 reason is
      present; run < 2 → absent.

  Full suite green before merge.

===============================================================================
  6. RELATIONSHIP TO EXISTING WORK
===============================================================================

  • Reuses the SAME class reference (buildClassReference) the blend, the P25/P75
    forecast band, reliability, and A2 already consume — no aggregate changes,
    no migration, no new DB state.
  • Stands apart from the baseline progress gap (health gauge's risk driver);
    B5/B6 ADD a peer view, never replace the calendar-based risk.
  • Completes the regression-module tail (A2 → B5/B6) from summary_of_next_work.txt.

===============================================================================
  7. VERIFICATION
===============================================================================

  - php artisan test (full suite) — must stay green
  - Test filters: B5/B6 unit class, CrossProjectSupplementIntegrationTest,
    RecommendationEngine tests
  - Manual: simulated project with seeded similar completed projects →
    status card shows the tier line; simulated 2-month-below-band project →
    badge + corrective recommendation appear; project with NO similar pool →
    neither shows.

===============================================================================
  8. DECISION LOG
===============================================================================

  1. B5-lite (banded tier, not exact percentile) — ✅ DECIDED (Aug 13 2026).
     Pool fits a single-site PMU (realistically < 100 similar completed
     projects; MIN_SIMILAR_PROJECTS floor = 3). Exact percentiles over tiny
     pools are false precision; a tier is honest and requires no new data.
  2. Classification location — AIPredictionService computes class_standing;
     HistoricalSimilarityService stays the reference-data owner (no new data).
     (If the block grows, extract ClassStandingService in Step 1.)
  3. At-risk semantics — run of ≥2 consecutive trailing months below P25;
     record at/above P25 disarms; <2 records never at-risk.
  4. Honesty — usable=false renders nothing; tier wording includes the
     "approximate bands" caveat; no peer claim without a pool.
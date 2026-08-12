# V2.5 — CROSS-PROJECT "SAFETY VALVES" HARDENING (C8)

CSPC PMU System — AI Module follow-up to Phase 8 (Cross-Project Historical Regression)

Status: 🔄 IN PROGRESS — Steps 1-4 IMPLEMENTED (uncommitted, pending commit+push);
        Step 5 (MIN_SIMILARITY_SCORE) DOCUMENTED as the next step (not yet
        implemented). Full suite green (614 passed / 2276 assertions).
        Present branch for review later (Rule 12).
Date: August 13, 2026
Branch (for implementation): `fix/cross-project-safety-valves` (from main)
Branch includes: this plan file only (commit 1). Code + tests land as later
                commits on the same branch, then present for review (Rule 12)
                before merging to main.
Plan file: textfiles/cross_project_safety_valves_plan.md (this file)

Reference Documents:
- textfiles/active_reference/ai_v2_phase8_cross_project_progress_log.txt
  (§7 Open Items, §9 "Recommended Changes Before Defense" — items 1 & 2)
- textfiles/regression_v2_5_cross_project_expansion_plan.md (§3 C8, §7)
- textfiles/active_reference/project_context_and_development_rules.txt
- app/Services/AI/HistoricalSimilarityService.php (the file being changed)
- textfiles/regression_v2_6_cross_project_prior_flow_plan.md
  (NEXT step after this plan — replaces the whole flow with a reference-class
   prior + honest hybrid; Step 5 below feeds its §4 Phase 1 pool-quality gate)

===============================================================================
  1. PROBLEM STATEMENT
===============================================================================

When a project has too little progress data, the app "borrows" the progress
curve of similar COMPLETED projects and uses it to fill the gaps (V2 Phase 8,
Cross-Project Historical Regression). Two safety weaknesses were flagged in
the Phase 8 log as "recommended before defense" and have never been fixed:

  Weakness 1 — Borrowing from too FEW similar projects.
    The aggregate curve (median / P25 / P75) is computed from whatever ends
    up in $validCurves (HistoricalSimilarityService.php:110-118). There is
    no minimum count. A median drawn from 1 or 2 curves is not a real
    "reference class" pattern — it is just the shape of one or two projects.

  Weakness 2 — The borrowed curve can be flattened to near-zero.
    The synthetic points are scaled by $scaleRatio, clamped inline at
    HistoricalSimilarityService.php:206: max(0.2, min(5.0, $scaleRatio)).
    If a project's FIRST real observation is tiny (e.g. 0.5% while the
    aggregate says ~2%), the ratio collapses toward 0.2 and the whole
    borrowed curve is flattened — the supplemented forecast becomes
    meaningless (Phase 8 log §7 Q5).

  Why this matters for the latest work (Phase 14 — Forecast Reliability):
    Phase 14 built an honest reliability score on top of the data factor
    (real observation count minus synthetic points). A sparse project
    supplemented from 1-2 shaky curves can still look more trustworthy than
    it should, and the near-flat borrowed curve manufactures predictions the
    model does not actually support.

===============================================================================
  2. OBJECTIVE
===============================================================================

  • Refuse to borrow when fewer than 3 similar projects have a usable
    curve (≥ 4 real observations each). The project then runs on its OWN
    data only, exactly as it did before Phase 8 existed.
  • Raise the synthetic scale-ratio floor from 0.2 to 0.5 so a borrowed
    curve can never be flattened into a meaningless near-flat line.
  • (Optional enhancement) Weight recently-completed similar projects
    higher than old ones, so "similar" really means similar today.
  • Stay backward compatible: projects with ≥ 3 good similar curves are
    untouched. No schema/route/view changes.

===============================================================================
  3. CHOSEN DIRECTION
===============================================================================

  Single choke point: gate inside HistoricalSimilarityService, in
  buildAggregateCurve(), because BOTH data-generator methods
  (generateSupplementDataset / generateFallbackDataset) build from it.
  When the gate trips, the generator returns null and
  AIPredictionService::applyCrossProjectData() (AIPredictionService.php:523)
  ALREADY falls back to the project's own data — no caller changes needed.

  Gate on "usable curves", not on raw matches: a matched project that has
  only 1-2 progress records cannot contribute a meaningful curve. Counting
  only curves with >= MIN_OBSERVATIONS_FOR_CURVE (4) observations is the
  honest measure.

  New class constants (matching the service's existing style — all other
  thresholds are class constants here; moving to config/ai.php is a Phase 12
  task per the Phase 14 plan).

===============================================================================
  4. IMPLEMENTATION STEPS (ordered; test each before proceeding — Rule 8)
===============================================================================

  -------------------------------------------------------------------------------
  Step 1 — MIN_SIMILAR_PROJECTS gate
  -------------------------------------------------------------------------------
  File: app/Services/AI/HistoricalSimilarityService.php

  (1) Add constant near the other thresholds (~line 20):
      private const MIN_SIMILAR_PROJECTS = 3;

  (2) In buildAggregateCurve(), right after the $validCurves filter:
      if ($validCurves->count() < self::MIN_SIMILAR_PROJECTS) {
          $this->aggregateCurve = [];
          return [];
      }
      (Cache the empty result so repeated calls stay consistent, exactly as
      the existing empty-aggregate branch does at :115-118.)

  Effect:
    • generateSupplementDataset() / generateFallbackDataset() see an empty
      aggregate and return null.
    • applyCrossProjectData() returns null → prediction uses the project's
      own data; regression data_source stays 'project_history'.
    • Phase 14 reliability in supplement mode then falls to the project's
      real observations (honest — usually Insufficient Data for a 1-obs
      project with < 3 usable similar projects).

  Note: shouldSupplement() still returns 'supplement' for 1-obs projects;
        that is fine — the generator is the place that refuses.

  -------------------------------------------------------------------------------
  Step 2 — Synthetic scale-ratio floor 0.2 → 0.5
  -------------------------------------------------------------------------------
  File: app/Services/AI/HistoricalSimilarityService.php

  (1) Add constants:
      private const MIN_SCALE_RATIO = 0.5;
      private const MAX_SCALE_RATIO = 5.0;

  (2) Replace the inline clamp at :206:
      // Before
      $scaleRatio = max(0.2, min(5.0, $scaleRatio));
      // After
      $scaleRatio = max(self::MIN_SCALE_RATIO, min(self::MAX_SCALE_RATIO, $scaleRatio));

  Effect:
    • A tiny first observation can no longer flatten the borrowed curve to
      ~20%; the lowest shape is now half the aggregate typical value.
    • The upper bound (5.0) is unchanged.

  -------------------------------------------------------------------------------
  Step 3 — (OPTIONAL) Recency weighting of similar projects
  -------------------------------------------------------------------------------
  File: app/Services/AI/HistoricalSimilarityService.php

  In findSimilarProjects(), after computeSimilarityScore(), multiply the
  candidate score by a recency factor derived from $candidate->actual_end_date
  (the completed project's real finishing date — field exists on Project and
  is date-cast). Example: projects completed within ~2 years score 1.0,
  decaying linearly to ~0.5 over ~5 years. This REORDERS which similar
  projects feed the aggregate (newer = more representative today) without
  changing the match/no-match logic.

  OPTIONAL: skip if a minimal change is preferred. Listed as its own step so
  it can be dropped (and documented as deferred) without touching Steps 1-2.

  -------------------------------------------------------------------------------
  Step 4 — [IMPLEMENTED] Similar-project audit details + dev console.log (C8)
  -------------------------------------------------------------------------------
  Purpose: make WHICH similar projects were actually used visible to the
  developer (and auditable), instead of just a count.

  Files touched:
  • app/Services/AI/HistoricalSimilarityService.php
  • app/Services/AI/AIPredictionService.php
  • app/Services/AI/AIPredictionPresenter.php
  • resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php

  (1) Extracted computeSimilarityScore() into computeSimilarityBreakdown()
      (returns per-factor points category/budget/duration/contractor/workers,
      same total — pure refactor, math unchanged, tests stayed green).
  (2) Added public getSimilarProjectsDetails(Project): for every similar
      project returns id/code/title, final score with recency multiplier,
      usable flag, and the per-factor breakdown.
  (3) Both dataset generators attach 'similar_projects_details' to their
      dataset (generateFallbackDataset + generateSupplementDataset).
  (4) AIPredictionService passes the key through; AIPredictionPresenter
      exposes it in regression_info.similar_projects_details.
  (5) The AI Prediction tab logs a [Cross-Project] block to the browser
      console when data_source is hybrid / similar_projects (NEVER for plain
      project_history) — seeded exactly by the new test.

  Also fixed (latent flake found by new test): a 1-obs project with short
  planned duration could make getSupplementTarget() return 2 → only 1
  synthetic point → merged X = [1,1] → "all X identical" regression crash.
  Tests were made deterministic (planned_duration_days=730); production math
  untouched.

===============================================================================
  STEP 1 — SIMPLER TERMS EXPLANATION
===============================================================================
  The app helps new projects by borrowing the progress shape of similar,
  already-finished projects. The problem: it would borrow even when only
  1 or 2 similar projects exist — and one or two shapes are not a reliable
  "pattern" yet. This change says: we only borrow when at least 3 similar
  projects have enough real history to draw a curve. Otherwise the project
  just stands on its own real data (and honestly shows "not enough data yet").

===============================================================================
  STEP 2 — SIMPLER TERMS EXPLANATION
===============================================================================
  When borrowing a curve, the app squeezes/streteches it to match the
  project's own starting point. If a project's first real number is very
  small, the old formula could squish the whole borrowed curve almost flat —
  a nonsense forecast. This change sets a lowest-allowable squeeze (50% of
  the typical borrowed shape) so borrowed curves always stay meaningful.

===============================================================================
  STEP 3 — SIMPLER TERMS EXPLANATION (OPTIONAL)
===============================================================================
  "Similar" currently ignores age — a project finished in 2018 counts the
  same as one finished last year. This optional change makes recently
  finished projects weigh more, so the borrowed pattern reflects how
  construction is being done NOW, not a decade ago.

===============================================================================
  STEP 4 — SIMPLER TERMS EXPLANATION
===============================================================================
  When the app borrows from similar projects, it used to only say how many it
  used ("4 similar project(s)"). Now it ALSO keeps a detailed receipt for each
  one: the project name, its similarity score, and WHY it scored that way
  (same type +30, similar budget +25, similar schedule +20, same contractor
  +15, similar crew size +10 — out of 100). That receipt is written to the
  developer console on the AI Prediction tab so we can see exactly which past
  projects influenced a forecast and how strongly.

===============================================================================
  STEP 5 — [PLANNED, NOT YET IMPLEMENTED] MIN_SIMILARITY_SCORE gate
===============================================================================
  Problem the user spotted in the Step 4 console log: a deliberately very
  DIFFERENT project (different budget, different schedule) still scored 40
  (category 30 + workers 10) and was still used for supplementation. The
  reason: findSimilarProjects() keeps EVERY candidate with score > 0
  (HistoricalSimilarityService.php:92) — and since category match alone is 30,
  a project only needs to be the SAME TYPE to qualify. There is no minimum
  similarity score.

  Fix: add a raw-score floor so "similar" means genuinely similar.

  (1) Add constant:
      private const MIN_SIMILARITY_SCORE = 50;   // value TBD — see Decision 5
  (2) In findSimilarProjects(), compute the RAW score first, filter with
      `rawScore >= MIN_SIMILARITY_SCORE`, THEN multiply by recency only to
      REORDER (so an old-but-genuinely-similar project is never disqualified,
      honoring the recency comment "never disqualifies a match").
  (3) Everything downstream inherits it automatically — getSimilarProjectsDetails(),
      buildAggregateCurve(), getSimilarProjectsCountFor(), console.log, disclaimer.
  (4) One choke point: no caller changes.

  Effect: the score-40 case above is excluded → fewer usable curves → the
  MIN_SIMILARITY_PROJECTS gate trips → project runs on its OWN data
  (data_source 'project_history') instead of borrowing from weakly-similar
  projects.

  NOTE — makes existing tests non-deterministic: the factory seeds RANDOM
  budget/duration, so seeded "similar" projects can score anywhere from 30-100.
  Tests MUST seed matching budget/duration/workers/contractor on both target
  and candidates to guarantee high scores (see Step 5 tests in §5).

  WARNING — data-quality tradeoff: budget/duration/workers return 0 when null
  on either side, so a genuinely similar project missing that metadata could
  also fall below the threshold. Threshold 50 still admits category-only + a
  partial secondary match. Threshold value options:
    40 = category + workers only          → does NOT fix the user's case
    50 = category + full duration match   → excludes the score-40 case
    55 = category + full budget match     → stricter
    75 = category + budget + duration     → strictest ("near-identical scope")

===============================================================================
  5. TESTING STRATEGY (Rule 8 — test each step before next)
===============================================================================

  New file: tests/Feature/Services/AI/HistoricalSimilarityHardeningTest.php
  (RefreshDatabase; mirrors the seeding style of
   tests/Feature/Services/AI/CrossProjectSupplementIntegrationTest.php:
   createProjectWithHistory + makeCategory helpers via CreatesProjectWithHistory)

  Step 1 tests:
    • 2 similar completed projects (6 months each) + 1-obs target →
      generateSupplementDataset returns null AND the generated report's
      regression data_source is 'project_history' (no hybrid).
    • Boundary: exactly 3 usable similar curves → aggregate built, supplement
      still works (hybrid).
    • Gate counts USABLE curves: 3 matched but only 2 with ≥ 4 observations
      → blocked (returns null).

  Step 2 test:
    • generateSyntheticPoints() with a tiny first actual (e.g. 0.5 vs
      aggregate ~2%) → scaled values reflect scaleRatio ≥ 0.5 (never the old
      0.2 flattening). Pass actualX/actualY directly so the test is pure math.

  Step 3 test (optional):
    • Two identical candidates differing only in actual_end_date → the newer
      one scores higher (appears first in findSimilarProjects()).

  Step 4 tests (DONE — in HistoricalSimilarityHardeningTest.php):
    • test_similar_project_details_flow_to_presenter_in_hybrid_mode — details
      (id/code/title/score/usable/recency/breakdown) reach regression_info
      in the presenter when cross-project activates.
    • Determinism fix: the 3 supplement-path tests now pin
      planned_duration_days=730 to avoid the all-identical-X flake.

  Step 5 tests (when implemented):
    • Deterministic seeding FIRST: fix approved_budget, planned_duration_days,
      estimated_workers, contractor_id on BOTH the target and the seeded
      candidates so scores are guaranteed ≥ threshold (the factory currently
      randomizes them → any threshold makes the existing tests flaky).
    • A candidate below MIN_SIMILARITY_SCORE is excluded from
      findSimilarProjects(), getSimilarProjectsDetails() and the aggregate
      (and is NOT counted toward MIN_SIMILAR_PROJECTS).
    • Threshold applies to the RAW score: an old project with raw 100×recency
      0.5 = 50 still passed (recency only reorders, never disqualifies).
    • Update the existing CrossProjectSupplementIntegrationTest seeding to
      matching scope data so behavior is unchanged by the gate.

  Existing tests that MUST stay green (verified against current code):
    • CrossProjectSupplementIntegrationTest::test_supplement_mode_prepends...
      seeds exactly 3 similar projects with 6 months each = 3 usable curves,
      so it still passes the new gate.
    • test_no_similar_projects_degrades_gracefully (0 similar) — unaffected.
    • test_threshold_reached_returns_none (6 obs) — unaffected.
    • AIPredictionTabRenderTest supplement-mode render — seeds 3 similar
      projects; verify count when implementing.

  Full suite before/after: composer test (SQLite :memory:, see phpunit.xml).

===============================================================================
  6. FILES TOUCHED
===============================================================================

  NEW:
    textfiles/cross_project_safety_valves_plan.md        (this plan)
    tests/Feature/Services/AI/HistoricalSimilarityHardeningTest.php

  MODIFIED:
    app/Services/AI/HistoricalSimilarityService.php      (Steps 1-4 + Step 5 when done)
    app/Services/AI/AIPredictionService.php              (Step 4 passthrough)
    app/Services/AI/AIPredictionPresenter.php            (Step 4 expose details)
    resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php (Step 4 console.log)

  WILL CHANGE IN STEP 5:
    app/Services/AI/HistoricalSimilarityService.php      (MIN_SIMILARITY_SCORE filter)

  NO CHANGES NEEDED:
    routes / schema / migrations. The null-fallback path already exists
    (AIPredictionService.php:523).

===============================================================================
  7. BACKWARD COMPATIBILITY
===============================================================================

  • Projects whose category has ≥ 3 usable similar completed projects:
    behavior is IDENTICAL.
  • Sparse projects with < 3 usable similar curves: lose the hybrid
    supplementation. They run on own data, and a 1-obs project correctly
    shows Insufficient Data instead of a borrowed forecast. This is the
    intended honesty repair, not a regression.
  • scale-ratio floor 0.2 → 0.5 changes the exact synthetic values for
    projects whose first observation was tiny, but no test asserts those
    exact values today.
  • No new DB columns, migrations, routes, or Blade keys.

===============================================================================
  8. DECISION LOG
===============================================================================

  Decision 1 — Gate in buildAggregateCurve() rather than shouldSupplement()
    Context: two methods must refuse (fallback + supplement); shouldSupplement
      only knows the OBSERVATION count, not how many usable curves exist.
    Options: gate shouldSupplement on findSimilarProjects()->count() /
      gate buildAggregateCurve on validCurves->count() / both.
    Decision: gate buildAggregateCurve on VALID curves.
    Rationale: single choke point both generators flow through, and it counts
      the curves that actually contribute to the aggregate, not raw matches.
    Consequences: shouldSupplement stays permissive; the generator refuses.

  Decision 2 — MIN_SIMILAR_PROJECTS default 3
    Context: Phase 8 log §9 item 1 recommends default 3 ("recommended before
      defense").
    Options: 2 / 3 / 5.
    Decision: 3, as a class constant.
    Rationale: matches the documented recommendation; 3 median/quartile
      curves give a minimal defensible reference band.

  Decision 3 — Scale-ratio floor 0.5 (was 0.2)
    Context: Phase 8 log §9 item 2 recommends default 0.5; current inline
      clamp is 0.2.
    Decision: raise the floor to 0.5 via named constants (MIN/MAX).
    Rationale: prevents near-flat borrowed curves; upper bound unchanged.

  Decision 4 — Recency weighting is OPTIONAL
    Context: Phase 8 log §9 item 3 lists recency as an enhancement.
    Decision: include as Step 3, droppable without touching Steps 1-2.
    Rationale: reorders (not disqualifies) matched projects; scope-control.

  Decision 5 — [PENDING] MIN_SIMILARITY_SCORE threshold value
    Context: user found a score-40 project (category 30 + workers 10 only)
      still being used for supplementation — no minimum score exists today.
    Options: 40 / 50 / 55 / 75 (see Step 5 breakdown table above).
    Status: NOT decided — awaiting user choice. Default leaning: 50.
    Note: must be applied to the RAW score BEFORE the recency multiplier so
      recency continues to reorder only (matching the existing comment).

===============================================================================
  9. VERIFICATION & ACCEPTANCE
===============================================================================

  [x] Steps 1-4 implemented; full suite green (614 passed / 2276 assertions).
  [ ] Step 5 (MIN_SIMILARITY_SCORE) implemented + tests + full suite green.
  [ ] Live check (dev DB pmu_system4): a sparse project whose category has
      < 3 usable similar projects reports data_source 'project_history'.
  [ ] Live check: a category with ≥ 3 completed projects still supplements.
  [ ] Branch presented for review (Rule 10 + Rule 12) before merging.

===============================================================================
  10. OPEN ITEMS / FLAGGED FOR FUTURE
===============================================================================

  • UI "why" note: when the gate skips supplementation, the AI Prediction tab
    does not currently explain why. A regression-block note (e.g.
    'similar_projects_skipped_reason' => 'Only N usable similar projects
    (need ≥ 3)') + small Blade badge is a cheap follow-up — deferred so this
    plan stays math-only.
  • config/ai.php: MIN_SIMILAR_PROJECTS, MIN_SCALE_RATIO, and the Phase 14
    reliability thresholds all join config/ai.php in Phase 12 (Architectural
    Enhancements). Step 5's MIN_SIMILARITY_SCORE should join them too.
  • Step 5 blocked on Decision 5 (threshold value) + deterministic test
    seeding. Do NOT implement until the threshold is chosen.
  • The Step 4 console.log writes per-similar-project breakdowns to the
    browser console; a `similar_projects_details` summary could later feed a
    small "Similar projects used" table on the AI Prediction tab (deferred).
  • Step 5's score-40 example (category 30 + workers 10 still borrowed) and the
    deeper flaws in this flow are analyzed in
    regression_v2_6_cross_project_prior_flow_plan.md (§3 findings, §11 note) —
    that plan is the next step after this one.

===============================================================================
  11. CONVERSATION CONTINUATION GUIDE
===============================================================================

  Next session:
    1. Steps 1-4 already committed on fix/cross-project-safety-valves.
    2. Implement Step 5: pick MIN_SIMILARITY_SCORE value (default 50) with the
       user (Decision 5), make seeding deterministic, add the Step 5 tests,
       run full suite.
    3. Continue from Step 5 into regression_v2_6_cross_project_prior_flow_plan.md
       (Phase 1 honest fixes + Phase 2 reference-class prior / blend).
    4. Present branch(es) for review; merge to main only on explicit approval.
    Confirm files with the user (Rule 1) before editing, since code may have
    changed since this plan was written.

===============================================================================
  END OF PLAN
===============================================================================
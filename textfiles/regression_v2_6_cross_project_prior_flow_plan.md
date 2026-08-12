# V2.6 — CROSS-PROJECT FLOW UPGRADE: REFERENCE-CLASS PRIOR & HONEST HYBRID

CSPC PMU System — AI Module follow-up to V2.5 (safety valves, C8) and Phase 8
(Cross-Project Historical Regression).

Status: 🔄 IN PROGRESS — Phase 1 §4.1 DONE (monotonic X-axis merge, Aug 13
        2026, on branch fix/cross-project-safety-valves). Phase 1 §4.2 is
        largely satisfied by V2.5 Step 5 (MIN_SIMILARITY_SCORE 50, DONE).
        Remaining: 4.3 (null-safe scoring), 4.4 (honest fit wording), then
        Phase 2. V2.5 safety-valves plan Steps 1-5 ALL DONE.

Date: August 12, 2026
Branch (for implementation): `fix/cross-project-prior-flow` (new, from main)
Branch includes: this plan file only (commit 1). Code + tests land as later
                commits on the same branch, then present for review (Rule 12)
                before merging to main.
Plan file: textfiles/regression_v2_6_cross_project_prior_flow_plan.md (this file)

Reference Documents:
- textfiles/cross_project_safety_valves_plan.md (V2.5 — completed Steps 1-4,
  Step 5 MIN_SIMILARITY_SCORE pending); this v2.6 plan is the "next step" it
  points to.
- textfiles/regression_v2_5_cross_project_expansion_plan.md (§3 A1/A3/A4, §7)
- textfiles/active_reference/ai_v2_phase8_cross_project_progress_log.txt
- app/Services/AI/HistoricalSimilarityService.php (the file most changed)

Research sources used for the recommended flow:
- Reference Class Forecasting (RCF) — Flyvbjerg 2006; HM Treasury Green Book;
  TII Ireland RCF Guidelines for National Road Projects ("identify a sample of
  past, similar projects — typically a minimum of 20-30; establish the risk
  distribution; adjust the estimate toward the class + widen credible interval").
- Hierarchical Bayesian pooling / transfer learning for short series (retail
  demand: JD.com pooling & boosting; SmallML hierarchical Bayes for SMEs;
  Bayesian nearest-neighbour / smoothing for supply chain). Core idea: the
  similar-entity data enters as a PRIOR over the model, with a shrinkage weight
  that weakens as real data accumulates — no fabricated observations.
- Construction-native work: AACE 55R-09 (S-curve analysis), earned-value (SPI/
  CPI/EAC) standards, kNN regression on similar project features (duration,
  value, type, contractor) which averages actual outcomes weighted by
  similarity.

===============================================================================
  1. PROBLEM STATEMENT
===============================================================================

The V2.5 safety-valves work (cross_project_safety_valves_plan.md) hardened the
existing cross-project feature but did NOT fix its core architecture. Review of
the code against how other systems do "borrow from similar projects" found
several real flaws (details in §3):

THE ROOT LIMITATION — "supplement for sparse projects ONLY". Cross-Project
Historical Regression (Phase 8) is currently used in EXACTLY ONE way: as a data
supplement for projects with too few observations. Documented authoritatively in:
  - textfiles/regression_v2_5_cross_project_expansion_plan.md §1 (:33-52):
    "shouldSupplement() gates it: 0 obs → none; 1 obs or < ⅓ planned months →
    supplement; ≥ threshold → none (cross-project is NEVER consulted for normal
    projects)... For every non-sparse project, HistoricalSimilarityService
    (aggregate curve, similar projects) is completely idle."
  - textfiles/gaps_and_features_missing.txt (:40-58): "LIMITATION confirmed:
    only 'supplement' mode is active... generateFallbackDataset exists but is
    UNUSED."
Because of this, the whole "similar project" pipeline is idle for normally-
tracked projects. Phase 2 §5.1-5.5 below is the fix: the reference-class blend
applies to ALL projects (w grows with real obs, so rich projects converge to
their own model) — i.e. this plan replaces the "sparse-only supplement" with
v2.5 Option A1 ("Model blend / prior (extends beyond sparse)", regression_v2_5
_plan §3 A1).

  1. The feature fabricates synthetic monthly observations and runs the
     regression over the merged (real + synthetic) set, then reports the
     resulting fit (e.g. "R² = 1.000 · Excellent") as if it were a real curve
     fit. That R² mostly measures fit to invented points — misleading, and it
     is why the Phase 14 reliability score has to "un-do" the synthetic count.
  2. In supplement mode the merged X-axis is malformed: synthetic points use
     month_index 1..needed and the real dataset RESTARTS at 1..realCount, so
     the merged X is non-monotonic with month 1 duplicated
     (HistoricalSimilarityService.php:602-610). The regression double-counts
     month 1 and misorders the tail.
  3. MIN_SIMILAR_PROJECTS = 3 is far below the RCF guidance (20-30+). A
     median/P25/P75 "band" drawn from 3 curves is statistically noise, yet the
     UI still frames it as a confident reference.
  4. Similarity weights are arbitrary (category 30 / budget 25 / duration 20 /
     contractor 15 / workers 10) and null-hostile (a missing budget/duration
     silently zeros that factor). Research ranks duration, value/cost, and
     type as the dominant progress predictors; contractor is weak.
  5. Curves are combined with an equal-weight median regardless of similarity
     score — kNN practice weights by similarity.
  6. No forecast interval: predictions and completion are single numbers,
     while both RCF and AACE S-curve practice are built around RANGES. The
     aggregate already computes P25/P75 but only the median feeds the model.
  7. 0-observation projects can never use cross-project data:
     shouldSupplement() returns 'none' for 0 obs, so generateFallbackDataset()
     is unreachable dead code (regression_v2_5_plan §3 A4).

===============================================================================
  2. OBJECTIVE
===============================================================================

  Phase 1 (honest fixes — small, low risk):
  • Fix the X-axis merge so real observations keep their true month indices and
    synthetic rows only fill genuinely-uncovered months (or extend the forecast
    beyond current month) — no duplicated month 1, monotonic X.
  • Gate pool quality before it is sold as reference: ship V2.5 Step 5
    (MIN_SIMILARITY_SCORE on the RAW score) and report the class honestly
    (n = number of usable similar projects, spread of their curves).
  • Re-word fit quality in hybrid mode ("category blend" instead of "Excellent
    curve fit · R² = ..." derived from synthetic rows).
  • Make missing budget/duration NOT silently zero a factor.

  Phase 2 (the better flow — the point of this plan):
  • Fit the project's OWN regression on real points ONLY.
  • Build the reference-class curve from similar completed projects (median +
    P25/P75 band, similarity-weighted).
  • BLEND: progress(t) = w · own(t) + (1 - w) · class(t), where w grows with
    real observation count (Bayesian partial-pooling shrinkage). Sparse project
    → mostly class; rich project → mostly own. No fabricated observations.
  • Output next-prediction / completion as a RANGE using the class P25/P75
    band (A3 + A5), and feed reliability from class homogeneity (spread).
  • Make 0-obs projects show a clearly-labeled "category baseline" curve
    instead of blank Insufficient Data (A4). (Product decision needed.)

===============================================================================
  3. RESEARCH FINDINGS — HOW OTHER SYSTEMS DO THIS
===============================================================================

  A. Reference Class Forecasting (RCF) — the industry's canonical answer.
     - Origin: Kahneman/Tversky "outside view"; adopted by UK HM Treasury,
       Irish TII, Oxford Global Projects, DfT.
     - Method: (1) assemble a reference class of comparable completed projects
       (20-30+; statistically validate homogeneity); (2) build the distribution
       of the outcome of interest; (3) regress the target's estimate toward the
       class average and EXPAND its credible interval toward the class interval.
     - Lessons for us: the class is a PRIOR + a DISTRIBUTION, not a source of
       fake data points; the deliverable is an adjusted RANGE, not a single
       curve. RCF underperforms when the class is too small or too dissimilar
       (Batselier & Vanhoucke 2016) — exactly our MIN=3 / score-40 problem.

  B. Hierarchical Bayesian pooling / transfer learning.
     - Used for short series across similar entities (retail JD.com 2022;
       SmallML 2025; supply-chain counts Chapados 2014; restaurant transfer
       2005/2020).
     - Core idea: pool information through a PRIOR over parameters; individual
       estimate shrinks toward the group; shrinkage weight decreases as the
       target's own data grows. Matches our user goal: "help sparse projects"
       WITHOUT inventing observations.
     - SmallML: "partial pooling... groups with little data borrow heavily from
       the population; groups with much data keep their own signal."

  C. Construction-native approaches.
     - Earned-value S-curves (AACE 55R-09): compare actual progress vs baseline
       PV curve; forecast via CPI/SPI extrapolation; track the S-curve BAND.
     - kNN regression on project features (duration, cost, type, contractor):
       average the ACTUAL outcomes of the k nearest past projects, weighted by
       distance/similarity. Non-parametric, transparent.
     - Stage/milestone ML (NZ multinomial logistic 2025): duration and value
       dominate progress explanation; type and contractor involvement add
       signal. Validates promoting duration+value in our scoring.

  What they all share and we lack:
     • The class enters as a PRIOR or a WEIGHTED COMBINATION — not as
       fabricated rows in the training set.
     • The output is a RANGE with honest uncertainty.
     • The class is large enough and quality-gated before it is relied on.
     • Similarity is feature-driven and null-safe.

===============================================================================
  4. PHASE 1 — HONEST FIXES (SMALL, LOW RISK)
===============================================================================
  File (primary): app/Services/AI/HistoricalSimilarityService.php

  4.1 Fix the hybrid X-axis merge (bug found during code review)  ✅ DONE
      - generateSupplementDataset(): do NOT restart real x at 1. Synthetic
        points fill months BEFORE the first real month only for months with no
        real record; real points keep month_index = real month.
        Simplest correct layout for a project with real months 1..R and planned
        duration P: synthetic spans P entirely, merged X is monotonic 1..P with
        real points OVERWRITING the synthetic value at their own month.
      - Effect: regression no longer double-counts month 1; forecast tail
        ordered correctly.
      - Implemented Aug 13 2026 (HistoricalSimilarityService::generateSupplementDataset
        rebuilds progressByMonth from the synthetic 1..P fill, overwrites real
        months, ksorts → monotonic X). Downstream current progress now reads
        $dataset['y'][current_month_index - 1] instead of end($dataset['y'])
        (AIPredictionService x2) so hybrid mode keeps the REAL last value.
      - Test: test_supplement_merge_x_is_monotonic_with_real_value_at_own_month
        (CrossProjectSupplementIntegrationTest). Full suite 616 passed /
        2309 assertions.

  4.2 Pool-quality gate (continue V2.5 Step 5)
      - MIN_SIMILARITY_SCORE on RAW score before recency (see safety-valves
        plan Step 5; Decision 5 value — default 50).
      - REPORT class honesty: add enough metrics to the regression block so the
        UI can say "blended from N similar projects, curve spread X" instead of
        implying one confident curve.

  4.3 Null-safe similarity
      - proximityScore() returns 0 when target or candidate missing a field.
        Missing data should neither fake a match nor silently tank one; decide a
        neutral/bypass handling (e.g. factor omitted from the denominator when
        data is absent) — documented tradeoff in Decision Log.

  4.4 Honest fit wording in hybrid/similar_projects modes
      - Presenter: when data_source != project_history, do NOT present the
        synthetic-derived R² as "Excellent curve fit". Show e.g. "Model blended
        with category reference curve (N=3 similar projects)".

===============================================================================
  5. PHASE 2 — REFERENCE-CLASS PRIOR + RANGE (THE BETTER FLOW)
===============================================================================
  Files: HistoricalSimilarityService, AIPredictionService (runRegression /
         applyCrossProjectData), RegressionDatasetBuilder, AIPredictionPresenter,
         ai-prediction.blade.php

  5.1 Keep regression on REAL points only
      - runRegression() runs on the real dataset (no synthetic rows).
      - The reference-class curve is used ONLY to (a) blend coefficients/
        predictions and (b) size the confidence band.

  5.2 Reference-class curve with similarity weighting
      - buildAggregateCurve(): keep median/P25/P75 per percentile, but weight
        each usable similar curve by its similarity score (kNN-style) so
        stronger matches dominate. (V2.5 strictly equal-weight.)

  5.3 Blend (partial-pooling shrink)
      - Let w = f(obsCount, totalPlannedMonths) — e.g. w grows from ~0 (sparse)
        to 1 (rich). Natural candidate without a stats lib:
            w = obsCount / (obsCount + k),   k tuned per category/reference size
        or w = clamp(obsCount / threshold_obs, 0, 1) with a floor.

      - progress_predicted(t) = w·own_model(t) + (1−w)·class_curve(t).
      - When w is low (sparse), the class dominates; when w→1, the project
        stands on its own data (V2.5's own-data fallback is a special case).

      ★ KEY: This is the fix for the "cross-project is supplement-only / not
        used by all projects" limitation (see §1 above + textfiles/
        regression_v2_5_cross_project_expansion_plan.md §3 A1 and textfiles/
        gaps_and_features_missing.txt :40-58). Unlike the current sparse-only
        supplement, this blend applies to EVERY project: normal projects still
        get a class prior (weighted small via §4.1 w), sparse projects get the
        full class curve.

  5.4 Range / interval output (A3 + A5)
      - Surface next-prediction and completion as P25/P75 band of the blended
        curve (lower/upper quaritle of the class), not a single number.
      - Presenter + blade: "predicted next month xx–yy% (P25–P75)".

  5.5 Reliability from class homogeneity
      - ForecastReliabilityService: additionally shrink the reliability score
        when the class band is wide or N is small (honest).

  5.6 0-obs fallback (A4) — product decision needed
      - Allow shouldSupplement() to return 'fallback' for 0-obs, with a clear
        UI label "category baseline estimate — not project-specific".

  Math note: all Phase 2 math is implementable in plain PHP (weighted median,
  interpolation, simple shrinkage) — no statistics library required.

===============================================================================
  6. SIMPLER TERMS — WHY THIS IS BETTER
===============================================================================
  TODAY: the app makes up fake monthly numbers from similar finished projects,
  glues them onto the real numbers, and fits a curve to the glued-together set.
  The fit then LOOKS better than it really is ("Excellent, 100%") because it is
  fitting invented points.

  BETTER: the app fits the curve to the REAL numbers only. The similar finished
  projects act as a "tutor" that pulls the forecast toward the typical shape of
  that category — strongly when we have little real data, weakly when we have
  lots. The forecast is reported as a RANGE (best case / worst case of similar
  projects) instead of a single confident number, and the app says honestly how
  many similar projects it used.

===============================================================================
  7. TESTING STRATEGY (Rule 8 — test each phase before next)
===============================================================================

  Phase 1 tests (extend CrossProjectSupplementIntegrationTest +
  HistoricalSimilarityHardeningTest):
    • Hybrid X is monotonic and month 1 appears once; real observations keep
      their true indices.
    • Missing budget/duration changes scoring per Decision §9.4.
    • Fit wording: hybrid/similar_projects presenter output no longer claims
      "Excellent" from synthetic R².
    • MIN_SIMILARITY_SCORE (V2.5 Step 5) tests.

  Phase 2 tests:
    • Blend weights: 1-obs project ≈ class-dominated (w small); rich project
      (obs ≥ threshold) w=1 → identical to own-only model.
    • P25/P75 band included in presenter output for blended modes.
    • Scores weighting: a higher-similarity curve influences the aggregate more.
    • 0-obs A4 fallback (if product-approved): data_source = similar_projects +
      category-baseline label.

  Full suite before/after each phase: composer test.

===============================================================================
  8. FILES TOUCHED (planned)
===============================================================================

  NEW:
    textfiles/regression_v2_6_cross_project_prior_flow_plan.md  (this plan)

  MODIFIED (Phase 1):
    app/Services/AI/HistoricalSimilarityService.php
    app/Services/AI/AIPredictionPresenter.php

  MODIFIED (Phase 2):
    app/Services/AI/HistoricalSimilarityService.php
    app/Services/AI/AIPredictionService.php
    app/Services/AI/ForecastReliabilityService.php
    app/Services/AI/Regression/RegressionDatasetBuilder.php
    resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php
    tests/Feature/Services/AI/CrossProjectSupplementIntegrationTest.php
    tests/Feature/Services/AI/HistoricalSimilarityHardeningTest.php
    (+ a new Phase 2 blend test file)

  UNCHANGED:
    routes / schema / migrations.

===============================================================================
  9. DECISION LOG
===============================================================================

  Decision 1 — Separate Phase 1 (fixes) from Phase 2 (prior flow).
    Context: V2.5 hardened the existing feature; flaws §3.1/§3.2 should be
    fixed regardless of the bigger redesign.
    Decision: land Phase 1 first (small, testable), then Phase 2.
    Consequences: regression behavior changes incrementally, not in one big bang.

  Decision 2 — Keep regression on REAL points only (Phase 2).
    Context: current code injects synthetic rows (inflates R²), which Phase 14
    reliability has to discount.
    Decision: run the model on real data; use the class only as prior/range.
    Consequences: predicted numbers change in supplement mode; wording must be
    updated; reliability gets cleaner (no synthetic-point subtraction needed).

  Decision 3 — Weighted aggregate (kNN-style), similarity weights.
    Context: current aggregate treats every usable curve equally.
    Decision: weight by similarity score in Phase 2.
    Consequences: aggregate shape shifts toward stronger matches.

  Decision 4 — Blending schedule.
    Options: shrinkage w = obs/(obs+k); or clamp(obs/threshold,0,1); or fixed
    steps.
    Decision: PENDING — tune with a backtest (log data_source + error, so we can
    choose k empirically). Revisit after C7-style accuracy tracking exists.
    Note: do NOT hard-code a blend we cannot validate.

  Decision 5 — MIN_SIMILARITY_SCORE value (carried from V2.5).
    Status: PENDING user choice; default 50. Do NOT implement until chosen.

===============================================================================
  10. VERIFICATION & ACCEPTANCE
===============================================================================

  [ ] Phase 1: hybrid X monotonic; month 1 single; fit wording honest.
  [ ] Phase 2: blend weight w behaves (sparse→class, rich→own).
  [ ] P25/P75 band surfaces in presenter/blade for hybrid modes.
  [ ] 0-obs fallback labelled (if approved).
  [ ] Full suite green after each phase.
  [ ] Branch(es) presented for review (Rule 10 + Rule 12) before merging.

===============================================================================
  11. OPEN ITEMS / FLAGGED
===============================================================================

  • The "difference is bad" example (score 40 = category + workers only): this
    is exactly the Weakness V2.5 Step 5 fixes — a same-category project with
    mismatched budget/duration still gets borrowed because score only has to be
    > 0. Validated by RCF research (small/dissimilar classes underperform).
  • Half the "no A4" path is product UX, not math — confirm whether 0-obs
    projects should show a category baseline curve.
  • Blend parameter k needs a backtest once we start logging predictions by
    data_source (C7).
  • 20-30+ reference projects is aspirational given the current DB size; the
    system must be HONEST at small N (report class size + spread) rather than
    pretend a 3-project median is a confident band.

===============================================================================
  12. CONVERSATION CONTINUATION GUIDE
===============================================================================

  Next session: decide MIN_SIMILARITY_SCORE (V2.5 Decision 5) → land Phase 1 →
  present branch → approve A4 product question → implement Phase 2 blend +
  range → tune k via backtest → present branch.
  Confirm files with the user (Rule 1) before editing.

===============================================================================
  END OF PLAN
===============================================================================
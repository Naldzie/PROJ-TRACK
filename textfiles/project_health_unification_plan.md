# PROJECT HEALTH UNIFICATION — DASHBOARD / INDEX vs AI MODULE

CSPC PMU System — cross-cutting fix for the "ON TRACK vs AT RISK"
inconsistency between the list views (admin/staff/contractor dashboards,
projects index) and the AI Prediction panel on the project detail page.

Status: 📌 PLAN CREATED (implementation NOT STARTED)
Date:   August 19, 2026
Branch: `feature/project-health-unification` (from main)
Plan file: textfiles/project_health_unification_plan.md (this file)
Research note: methodology comparison from public PM-tool documentation is
included in §3 (Problem Statement) and §4 (Chosen Direction) so the chosen
definition is defensible (capstone/defense material).

Reference Documents:
- textfiles/active_reference/project_context_and_development_rules.txt
  (dev rules — branch workflow Rule 12, plan-file template, comment rules)
- textfiles/active_reference/AI_MODULE_HANDOVER.md (AI module code map)
- textfiles/summary_of_next_work.txt (master next-work list)
- app/Services/AI/AIPredictionPresenter.php (AI gap-based health — the
  source of truth the list views must agree with)
- app/Services/ProjectStatusService.php (existing schedule-variance tiers)
- app/Models/Project.php (`isOverdue()`/`isAtRisk()`/`getRiskColor()`)
- resources/views/dashboard/partials/dashboard-table-rows.blade.php
- resources/views/projects/index.blade.php
- resources/views/contractor/dashboard.blade.php
- app/Http/Controllers/DashboardController.php
- app/Http/Controllers/ProjectController.php

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name
  Construction Project Monitoring System (Capstone Project System) — PROJ-TRACK

Client
  CSPC Project Management Unit (PMU)

Framework / Language / DB
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests)

Architecture
  Laravel Service-Oriented. Controllers stay thin (Receive Request → Call
  Service → Return Response). Business logic lives in app/Services/.
  Three role route groups (/admin, /staff, /contractor). `ProjectController`
  is shared by all roles; authorization is inside the controller.

Core workflow
  Project Created → Baseline Schedule Prepared → Contractor Begins Work →
  Monthly PMU Inspection → Actual Progress Recorded → Compared Against
  Baseline → Management Decision → Completion or Extension.

Design philosophy
  Decision-support system. Purpose = early-warning information, NOT to replace
  the project manager. Final decisions always remain with the PMU.

===============================================================================
  2. STATUS SUMMARY
===============================================================================

  ✅ COMPLETE — health definition inside the AI Prediction panel
       (`AIPredictionPresenter::presentNormal()`, AIPredictionPresenter.php
       lines 456-518): risk from the CURRENT progress gap vs the effective
       baseline at today. classifyRiskFromGap thresholds (lines 1150-1165):
       gap ≤ 5 → Low → "ON TRACK"; gap < 10 → Moderate; gap < 15 → High;
       gap ≥ 15 → Critical; every non-Low level renders "AT RISK".
       (AIPredictionPresenter.php line 517.)

  ✅ COMPLETE — detail-page schedule status
       (`ProjectStatusService::getProjectStatus()`, ProjectStatusService.php
       lines 11-90): variance-based tiers (ahead ≤ -5 / on_track ≤ 5 /
       low_risk < 10 / medium_risk < 15 / high_risk ≥ 15), shown on the
       detail Overview tab. Already consistent in spirit with the AI gap.

  ❌ BROKEN / MISMATCHED — dashboard + projects index health
       Uses deadline-only heuristics on the Project model instead of the
       progress gap:
         Project::isOverdue()  = past planned_end_date AND progress < 100
         Project::isAtRisk()   = days remaining < 15 AND progress < 90
       (Project.php lines 206-230, 304-313.)
       Consequence: a project 40% behind plan with months left shows green
       "ON TRACK" on the dashboard/index while the AI panel says "AT RISK".

  ⬜ NOT STARTED — shared single source of truth for health
  ⬜ NOT STARTED — no-data / non-active neutral states on list views
  ⬜ NOT STARTED — record-based escalation flags (issues / risks / extensions)

===============================================================================
  3. PROBLEM STATEMENT
===============================================================================

Health is NOT a stored DB column. It is recomputed on the fly in every view,
and the list views and the AI panel use two unrelated definitions:

  List views (dashboard rows, projects index, contractor dashboard):
      AT RISK  ⇔  planned_end_date within 15 days AND progress < 90%
      OVERDUE  ⇔  past planned_end_date AND progress < 100
      else        "ON TRACK" (green) — INCLUDING projects far behind plan
                  that are months from their deadline, and projects with no
                  progress records at all.

  AI panel (project detail → Intelligent Prediction Summary):
      gap = expected_progress(today) − actual_progress
      gap ≤ 5    → ON TRACK
      gap > 5    → AT RISK (Moderate / High / Critical severity)

So the same project can simultaneously be flagged "AT RISK" in the AI panel
and green "ON TRACK" on the dashboard/projects page. This is a real,
user-visible inconsistency (reported 2026-08-19).

Secondary defects discovered while tracing the mismatch:
  1. The projects index "At Risk" metric card counts only the CURRENT
     paginated page: `$projects->filter(fn($p) => $p->isAtRisk())->count()`
     (projects/index.blade.php line 85). `metrics['at_risk_projects']` is a
     dead placeholder (`0`) in ProjectController::index() line 129.
  2. The dashboard-table-rows partial calls `isOverdue()`/`isAtRisk()`/
     `getRiskColor()` up to ~7 times per row; each call runs its own
     `getProgressPercentage()` → DB query (N+1), despite the partial already
     receiving pre-fetched `latestKnownProgress`/`currentMonthProgress`/
     `currentMonthBaseline` maps.
  3. The dashboard "At Risk" stat card + alert pill + `?filter=atrisk`
     deep-link all use the deadline definition, so counts disagree with the
     badges once the badge definition changes.

Why other tools matter (research summary — see §4 for full comparison):
  Wrike (pre-AI), Alfabet, Deltek, Micro Focus PPM, TeamDynamix, and the
  Earned Value Management (PMI) standard all define schedule health as
  "actual progress vs planned progress at today" (SPI = EV/PV, or a
  percentage deviation), NOT as a deadline-countdown heuristic. Issues and
  risks are health inputs in Wrike/TDX/PPM ("at least one risk → amber").
  Non-active statuses and missing dates render neutral/grey (Wrike grey,
  PPM Express "N/A") — never green. This plan aligns the list views with
  that same objective, gap-based standard that the AI panel already uses.

===============================================================================
  4. CHOSEN DIRECTION
===============================================================================

Single source of truth + composite worst-signal health, aligned to the AI
panel and to the industry standard.

4.1 Health definition (unified, gap-based)

  level        condition (Ongoing projects)
  -----------  ---------------------------------------------------------------
  OVERDUE      past effective planned_end_date AND actual progress < 100
  AT RISK      not overdue AND gap = expected(today) − actual > 5
               (severity from the gap: Moderate < 10, High < 15, Critical ≥ 15)
  ON TRACK     not overdue AND gap ≤ 5 (includes ahead-of-plan, gap ≤ -5)
  NO DATA      ongoing but ZERO progress records (grey — honest, matches the
               AI panel's INSUFFICIENT DATA / NO ACTUAL DATA states)
  NEUTRAL      status = Planned / Suspended / Cancelled (grey — never green)
  COMPLETED    completion-delay badge (on-schedule / late / early) — unchanged

  gap = expected_progress(today) − actual_progress
  expected_progress(today) = the AI presenter's effective-baseline lookup
    (AIPredictionPresenter.php calculateExpectedProgress, lines 994-1106):
    1) current-month progress-history planned_progress_percent
    2) current-month baseline-schedule planned_progress_percent
    3) latest progress-history planned_progress_percent
    4) last baseline schedule with planned_date ≤ today
    5) time-based fallback (days elapsed / total days) × 100
  Uses DateHelper::now() (simulated date respected in non-production).

4.2 Single source of truth — new ProjectHealthService

  New service at app/Services/ProjectHealthService.php owning:
    • classifyGap(float $gap): array — the SAME thresholds as the AI
      presenter. AIPredictionPresenter::classifyRiskFromGap() is deleted and
      delegates to this method so the two can never diverge again.
    • expectedProgress(Project, ?context): float — the 5-branch effective-
      baseline lookup, with optional pre-fetched context to avoid N+1.
    • determine(Project, ?context): array — returns a normalized health
      result: level, label, risk (Low/Moderate/High/Critical/unknown), gap,
      expected, actual, and the display classes (fill/risk/dot). Pure and
      unit-testable when context is supplied.

  Context object (all optional) that callers with pre-fetched data pass in:
    latest_progress        (ProjectProgressHistory|null — actual)
    current_month_history  (planned_progress_percent)
    current_month_baseline (planned_progress_percent)
    latest_history         (planned_progress_percent)
    escalation flags       (open_high_issues, aging_issue_count,
                            open_high_risks, pending_extension) — §4.4

4.3 Phase 2 — escalation flags (worst-signal-wins, records-based only)

  A project that is ON TRACK by schedule is still escalated to AT RISK when
  it carries real record-based warning signs, matching Wrike/TDX/MicroFocus
  ("issues and risks are health inputs"):
    • ≥1 OPEN issue with severity High or Critical  (Issue::unresolved())
    • ≥1 unresolved issue aging > 30 days           (Issue::aging(30))
    • ≥1 OPEN risk-register entry with risk_score ≥ 7  (Risk::highRisk())
    • ≥1 PENDING extension request                  (ProjectExtension::pending())

  Design rule respected (from AIPredictionPresenter.php lines 496-499):
  forecast/prediction data is deliberately NOT mixed into health status. The
  AI forecast (predicted_delay_days, class-standing at_risk) stays in the
  detail tab and in the badge TOOLTIP only — it never drives the badge color.
  Escalation signals are hard, records-based facts.

  Why no budget/cost dimension: the system stores only approved_budget / a
  proposal's estimated_budget — there is NO actual-spend tracking (confirmed
  by schema grep; client excluded budget burn-rate tracking in
  summary_of_next_work.txt §5). Cost health is therefore impossible and is
  explicitly FLAGGED FOR FUTURE (§10).

4.4 UI presentation (kept minimal)

  Badge text stays 3-4 state: ON TRACK / AT RISK / OVERDUE / NO DATA
  (+ NEUTRAL for non-active). AT RISK severity is shown via color intensity
  (Moderate → amber .risk-medium, High/Critical → red-orange .risk-high) and
  a title/tooltip: "Behind plan by X%" or "Open critical issue" / "Pending
  extension" etc. Progress-bar fill and data-overdue/data-atrisk JS filters
  derive from the SAME determine() result.

===============================================================================
  5. IMPLEMENTATION STEPS
===============================================================================

Ordered; each step is tested before the next (Rule 8), and the user is
informed at each phase boundary (Rule 10).

Step 1 — ProjectHealthService + classifyGap extraction      ⬜ NOT STARTED
  • Create app/Services/ProjectHealthService.php with classifyGap(),
    expectedProgress(), and determine() per §4.1/§4.2.
  • Delete AIPredictionPresenter::classifyRiskFromGap() (lines 1150-1165);
    delegate to ProjectHealthService::classifyGap(). Output strings must
    stay identical: 'Low'/'Moderate'/'High'/'Critical'.
  • Unit tests: tests/Unit/Services/ProjectHealthServiceTest.php
    (classifyGap boundaries: -5/5/10/15; determine() outcomes via context,
    no DB).

Step 2 — Dashboard rows (admin/staff)                       ⬜ NOT STARTED
  • dashboard/partials/dashboard-table-rows.blade.php: build context from
    the already-fetched maps and call determine(); replace fillClass,
    riskLabel/riskClass/dotColor, and the data-overdue/data-atrisk
    attributes. Fixes the per-row N+1 at the same time.
  • Feature test: tests/Feature/DashboardHealthTest.php — behind-schedule
    project renders "AT RISK"; on-plan renders "ON TRACK"; overdue renders
    "OVERDUE"; no-records renders "NO DATA"; on-plan + open critical issue
    escalates to "AT RISK".

Step 3 — Projects index (grid + table + metric card)       ⬜ NOT STARTED
  • ProjectController::index(): eager-load progressHistory + baselineSchedules
    (+ withCount issues/risks/extensions for Step 5 flags); compute a per-
    page health map; compute an ALL-ongoing health map for the metric card
    and the atrisk filter id-set.
  • projects/index.blade.php: grid + table @php blocks consume the health
    map; fix the "At Risk" metric card to count ALL non-archived ongoing
    at-risk projects (not the paginated page); drop the dead
    metrics['at_risk_projects'] placeholder.
  • Feature assertions on ProjectsRenderTest (or a new ProjectHealthIndexTest):
    behind-schedule project shows "AT RISK" in both grid and table views;
    metric card reflects the full count.

Step 4 — Dashboard counts + filters                          ⬜ NOT STARTED
  • DashboardController::index(): atRiskCount = PHP-classified gap-based
    count over all ongoing (excludes overdue — separate card/pill exists).
  • DashboardController::filter(): 'atrisk' branch returns gap-based at-risk
    projects (eager-loaded, classified in PHP).
  • ProjectController::index(): '?filter=atrisk' deep-link becomes gap-based
    (compute the id-set from the all-ongoing map, then paginate those ids).
  • 'overdue' filter/pill/count stay deadline-based (well-defined, unchanged).

Step 5 — Escalation flags (§4.3)                            ⬜ NOT STARTED
  • ProjectHealthService::determine() accepts escalation context; add the
    worst-signal-wins overlay in Step 2-4 callers (withCount/relation data).
  • Tooltip text explaining the reason behind AT RISK.
  • Unit tests for the escalation overlay + feature tests asserting the
    on-plan-but-escalated case.

Step 6 — Contractor dashboard                                ⬜ NOT STARTED
  • contractor/dashboard.blade.php: same swap using its pre-fetched maps
    (health currently mirrors isOverdue/isAtRisk inline, lines 568-587).
  • Keep ContractorDashboardTest green.

Step 7 — CSS                                                 ⬜ NOT STARTED
  • public/css/dashboard/dashboard.css + public/css/admin/projects/
    projects.css: grey .no-data / .neutral badge state; AT RISK severity
    coloring (reuse .risk-medium/.risk-high; add .risk-critical if needed).

Step 8 — Full suite + verification                           ⬜ NOT STARTED
  • php artisan test (full suite) must stay green (Project 7 fixture and all
    AI expectations unchanged).
  • Manual browser pass on the simulated-date dev widget: a project shown
    "AT RISK" in the AI panel now also shows "AT RISK" on the dashboard and
    projects page.

===============================================================================
  6. SESSION LOGS
===============================================================================

2026-08-19 (plan only — no code yet)
  • Traced the health pipeline end to end. Confirmed health is computed on
    the fly, not stored; list views use deadline heuristics
    (Project::isAtRisk, Project.php 206-230) while the AI panel uses the
    progress gap vs the effective baseline at today
    (AIPredictionPresenter.php 456-518, 1150-1165).
  • Researched how external PM tools define/display project health
    (Wrike, EVM/SPI PMI, Micro Focus PPM, TeamDynamix, Alfabet, Deltek,
    PPM Express) — findings recorded in §3/§4.
  • Confirmed via schema grep that no actual-cost/spend tracking exists, so
    cost/budget health is out of scope (matches the client exclusion in
    summary_of_next_work.txt §5).
  • Created branch feature/project-health-unification + this plan file.

===============================================================================
  7. OPEN QUESTIONS / STILL TO RESOLVE
===============================================================================

  1. Pending-extension escalation: should a pending extension ALWAYS escalate
     an on-track project to AT RISK, or only when the request has sat beyond
     N days (e.g. > 7)? Decision recorded in §8 once confirmed with user.
  2. NO DATA state wording: "NO DATA" vs "NO ACTUAL DATA" (AI panel uses
     "INSUFFICIENT DATA" / "NO ACTUAL DATA"). Default chosen: "NO DATA".
  3. Planned projects on the index currently show a green "ON TRACK" badge;
     this plan changes them to a neutral grey badge. Confirm this is desired
     (it matches Wrike/PPM Express behaviour, but is a visible change).
  4. Should escalation flags also raise the At Risk COUNT on the dashboard
     stat card / metric card (yes, by design — the count comes from the same
     determine() result).

===============================================================================
  8. DECISION LOG
===============================================================================

Decision 1 — Unified health definition = progress gap vs baseline at today
  Context: dashboard/index (deadline heuristic) disagree with the AI panel
  (progress gap). The AI panel is the newest, industry-standard signal.
  Options considered: (a) keep deadline heuristic everywhere; (b) unify on
  the progress gap; (c) two badges.
  Decision: (b) — unify on the progress gap, with OVERDUE kept as the
  deadline-based exception.
  Rationale: matches the AI panel (the complaint), the EVM/SPI standard, and
  Wrike's deviation metric; deadline proximity remains visible in the
  Deadline column and as OVERDUE.
  Consequences: more projects will show AT RISK; the atrisk count/filter/
  pill must move to the same definition (Steps 3-4).

Decision 2 — classifyRiskFromGap becomes a shared method on ProjectHealthService
  Context: two copies of the same thresholds would drift again.
  Options: duplicate vs extract.
  Decision: extract to ProjectHealthService::classifyGap();
  AIPredictionPresenter delegates.
  Rationale: single source of truth; output strings unchanged so no AI tests
  break.
  Consequences: AI presenter behavior is byte-for-byte identical.

Decision 3 — NO DATA / NEUTRAL grey states, never green
  Context: the AI panel honestly shows grey for insufficient/no-actual data;
  list views force green ON TRACK for the same projects.
  Decision: ongoing-with-no-records → grey "NO DATA"; Planned/Suspended/
  Cancelled → grey neutral.
  Rationale: honesty (the module's core theme) and consistency with
  Wrike/PPM Express grey/N/A behaviour.
  Consequences: visual change on the list views; recorded in §7 Q3 for user
  confirmation.

Decision 4 — Escalation flags are records-based only; AI forecast never
             drives the badge color
  Context: the codebase deliberately keeps forecast data OUT of health status
  (AIPredictionPresenter.php 496-499).
  Decision: escalate AT RISK from open High/Critical issues, aging unresolved
  issues (> 30 days), open high-risk register entries (score ≥ 7), and
  pending extensions. AI predicted_delay_days / class-standing show in
  tooltips only.
  Rationale: forecast may be wrong; open records are facts; respects the
  existing design rule.
  Consequences: on-track-by-schedule projects can still appear AT RISK with
  an explanatory tooltip.

Decision 5 — Budget/cost health is NOT part of this change
  Context: no actual-spend tracking exists (schema grep), and the client
  excluded budget burn-rate tracking.
  Decision: exclude; flag cost/EVM (CPI) as future work.
  Rationale: cannot compute a real cost variance from approved_budget alone.
  Consequences: recorded in §10 FLAGGED FOR FUTURE.

===============================================================================
  9. FILES TOUCHED
===============================================================================

  (Implementation — planned, NOT yet modified)

  Created:
  - app/Services/ProjectHealthService.php            (new — single source of truth)
  - tests/Unit/Services/ProjectHealthServiceTest.php (new)
  - tests/Feature/DashboardHealthTest.php            (new)
  - tests/Feature/ProjectHealthIndexTest.php         (new, or extend ProjectsRenderTest)

  Modified:
  - app/Services/AI/AIPredictionPresenter.php        (delegate classifyRiskFromGap)
  - resources/views/dashboard/partials/dashboard-table-rows.blade.php
  - resources/views/projects/index.blade.php
  - resources/views/contractor/dashboard.blade.php
  - app/Http/Controllers/DashboardController.php     (atRiskCount + filter 'atrisk')
  - app/Http/Controllers/ProjectController.php       (eager loads + health map + filter)
  - public/css/dashboard/dashboard.css               (no-data/neutral/severity)
  - public/css/admin/projects/projects.css           (no-data/neutral/severity)

  Explicitly NOT touched:
  - app/Models/Project.php isOverdue()/isAtRisk()/getRiskColor() — kept for
    backward compatibility (notification scan, legacy views).
  - resources/views/admin/projects.blade.php          (superseded — controller
    returns projects.index).
  - resources/views/projects/archived.blade.php       (archived projects; not in
    the active health view).

===============================================================================
  10. FLAGGED FOR FUTURE
===============================================================================

  - Cost / EVM health (CPI, CV): requires actual-spend tracking, which does
    not exist and is client-excluded. If spending data arrives, add a Cost
    dimension to ProjectHealthService (Micro Focus PPM weighted-composite
    model is the reference).
  - Trend-aware health: "recovering vs deteriorating" (Gather/Deltek
    insight). The AI module already computes a trend; wiring it into the
    composite health score is a later refinement.
  - Configurable thresholds: move the 5/10/15 gap bands and escalation rules
    into config (matches AI Phase 12 "configurable thresholds" idea).
  - Class-standing (B6 "behind peers N months") as a badge tooltip on list
    views (already surfaced in the AI panel; currently planned as tooltip-
    only, not a color driver).

===============================================================================
  11. CONVERSATION CONTINUATION GUIDE
===============================================================================

When resuming this feature:

1. Assume main is stable with the regression-module tail CLOSED (see
   summary_of_next_work.txt). This branch contains ONLY the plan file.
2. Read textfiles/active_reference/project_context_and_development_rules.txt
   (Rule 8: test each step; Rule 10: inform user at phase boundaries;
   Rule 12: never merge to main without approval).
3. Read this plan + the current versions of the files in §9 before coding
   (Rule 1 — never assume code is unchanged).
4. Implement Step 1 first (ProjectHealthService + classifyGap extraction +
   its unit tests), then confirm the AI unit suite still passes
   (php artisan test --filter=Services/AI) and the Project 7 fixture is
   unchanged before moving to Step 2.
5. Keep AIPredictionPresenter::classifyRiskFromGap() output IDENTICAL after
   delegation ('Low'/'Moderate'/'High'/'Critical').
6. Report back at each phase boundary (Rule 10); update this file's
   SESSION LOGS (§6), status badges (§2), and FILES TOUCHED (§9) as you go.
7. Do NOT merge to main without the user's explicit approval (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================
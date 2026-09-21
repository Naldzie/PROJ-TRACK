# RISK REGISTER — HIDDEN FROM THE UI (the tab is "Issues" again)
# Parent refs: textfiles/risk_register_plan.txt (the original gap #20 build)
#              AGENTS.md → Client Workflow Notes (the register was optional, pending
#                the client's word)
# Date: September 21, 2026
# Status: ✅ BUILT on phase-40-hide-risk-register (from develop 14624f6). Browser
#         check owed by the user, then the merge question.

===============================================================================
  1. THE ASK (user, 2026-09-21)
===============================================================================

  "Comment out the whole risk table and register on the Issues & Risks tab (and
  rename it back to Issues), as our client said they do not list down possible
  future risks — only current, on-going or faced issues and problems. Look at the
  related code, as commenting them out might result in failed tests."

  This settles the AGENTS.md note "the Risk Register exists as an optional feature…
  may be removed or reworked later based on client feedback": the client does not
  use it.

===============================================================================
  2. WHAT WAS FOUND (traced in source 2026-09-21)
===============================================================================

  UI — everything of the register enters the page through ONE include in
    components/tabs/issues.blade.php:
      partials/risk-register.blade.php → the section (summary cards + table),
      modals/risk_modal + modals/risk_to_issue_modal, risk-register.css,
      risk-register.js.
    Plus, on the same tab: the All / Issues / Risks toggle (markup + its JS), and
    the "from Risk: …" link on issue rows (calls switchToRisksTab from
    risk-register.js). In project_detail.blade.php: the tab label, the
    getRisksApiUrl() helper (used only by risk-register.js) and a typeof-guarded
    loadTabRisks() call. Overview tab: the card title "Issues & Risks".

  NOT the register (left as is): the Progress tab's "Risk Flags & Alerts" (schedule
    slippage), project risk LEVEL / health badges, the RiskLevelChanged notification.

  BACKEND consumers of risk records:
    · AIPredictionPresenter + DashboardController feed "open high-priority risk" into
      ProjectHealthService's record-based escalation — DISABLED
      (config/ai.php 'health.escalation_enabled' => false), so leftover risk rows
      cannot change any health badge.
    · RiskController (+ routes), RiskReported notification, IssueController's
      source_risk_id handling, project deletion cleanup — unchanged; nothing in the
      UI calls them any more.
    · The AI analysis / recommendation prompts do NOT read the register.
    · The project report (phase-39) does NOT include the register.

  LIVE DATA (pmu_system4): 4 Open risks, all on project 142 (test data); 0 issues
    converted from a risk — so the hidden "from Risk" link hides nothing real.

  TESTS that depended on the UI: only two label assertions —
    OverviewTest (card "Issues & Risks") and ProjectsRenderTest (tab label).
    RiskRegisterTest (35 tests) calls the API only and never renders the tab, so it
    is unaffected by hiding the UI.

===============================================================================
  3. WHAT WAS DONE
===============================================================================

  Everything is hidden with BLADE comments ({{-- … --}}, stripped server-side, so
  none of it reaches the browser), each marked "RISK REGISTER HIDDEN":
    · issues.blade.php — the risk-register @include (section, modals, CSS, JS), the
      All/Issues/Risks toggle markup and its JS, the "from Risk" link on issue rows.
      Header "Issues & Risks" → "Issues"; subtitle "Track project issues and
      forward-looking risks" → "Track issues and problems encountered on the
      project". A header comment explains how to restore it.
    · project_detail.blade.php — tab label → "Issues" (was "Issues & Risks"),
      getRisksApiUrl() and the loadTabRisks() call hidden.
    · overview.blade.php — card title → "Issues".
  Deliberately KEPT (restoring = removing the comment wrappers): RiskController,
  the risk routes, the Risk model, the partial/modal files, risk-register.js/.css,
  RiskRegisterTest. The typeof-guarded loadTabRisks() calls in issues.blade.php's
  delete handler and public/js/admin/projects/issues-modal.js are harmless without
  risk-register.js and were left.
  Lesson noted: Blade comments do not nest — a comment containing the literal
  "--}}" ends early and prints the rest (caught while writing the header note).

  TESTS: OverviewTest and ProjectsRenderTest now assert "Issues" (and NOT "Issues &
  Risks"); new ProjectsRenderTest::test_project_detail_hides_the_risk_register —
  the Issues section renders, and none of: #risksSection, "Risk Register",
  risk-toggle-btn, #riskModal, #riskToIssueModal, risk-register.js/.css,
  switchToRisksTab(), or the "RISK REGISTER HIDDEN" marker reaches the page.
  Mutation: the @include put back → that test red.
  SUITES: PHP 1301 passed / 0 failed; JS 267 / 267 / 0; all Blade views compile.

  ⬜ BROWSER CHECK OWED (user): the tab reads "Issues"; no All/Issues/Risks toggle,
    no Risk Register section; adding / editing / deleting an issue still works; the
    Overview card reads "Issues"; the browser console shows no errors on the tab.

===============================================================================
  4. POSSIBLE LATER
===============================================================================

  · If the client never wants it back, the register could be REMOVED rather than
    hidden (routes, controller, model, migration-kept table, notification type,
    tests) — a separate, deliberate change.
  · Old "Risk Reported" notifications stay visible in inboxes (history).

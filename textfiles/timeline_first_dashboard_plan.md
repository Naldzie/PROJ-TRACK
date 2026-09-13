# Timeline-first admin dashboard — implementation log

Date: 2026-09-10
Scope: new "Timeline" tab on the shared admin/staff dashboard
(`resources/views/admin/dashboard.blade.php`, rendered for both roles via
`DashboardController@index`).

## What was built

Deadline-first Gantt-style hero strip, styled as a sibling of the existing
Overview / Calendar / Campus Map tabs (same tab switcher, spacing, radius,
sans-serif; flat navy `#1e3a8a` + teal `#0f766e` surfaces, hairline borders
only, no shadows/gradients, sentence case).

- Header: "This quarter" / "This year" title, date-range subtitle, range
  toggle (quarter/year), "New project" + "All projects" actions.
- Timeline rows: project name (fixed col) + day-proportional duration bar +
  deadline (far right), sorted by deadline soonest-first (controller
  `orderBy('planned_end_date')`). Bar/deadline colour follows
  `ProjectHealthService::determine()` — green on track, amber at risk, red
  overdue, grey no-data. Pending-extension rows get an amber badge.
- Hovering a bar shows a "Planned X% · Actual Y%" tooltip (same
  expected/actual values the health badge uses — cannot disagree with the
  Overview table).
- KPI strip below: Total projects, Ongoing, Overdue (deep-links into the
  projects list like the Overview cards), Extensions granted (toggles an
  in-place extension-rows-only filter; there is no global extensions page
  to link to).
- Whole row is an `<a>` to the role-aware project detail view.
- Timeline tab is first + default for admin; staff keep Overview default
  (staff query is scoped to assigned projects via existing
  `scopeToAssigned()`, so staff see only their own rows).

## Files

- `app/Http/Controllers/DashboardController.php` — `index()` now also
  passes `$timelineProjects` (all ongoing, no 10-row cap),
  `$timelineProgress` (same `getProgressData()` helper as the table),
  `$timelineQuarter` / `$timelineYear` ranges.
- `resources/views/dashboard/partials/timeline-widget.blade.php` — new.
- `public/css/dashboard/timeline.css` — new (wired into `app.blade.php`
  + `stafflayouts.blade.php` per no-vite `public/` convention).
- `public/js/dashboard-timeline.js` — new (range re-render, tooltip,
  extension filter; row clicks are native links).
- `resources/views/admin/dashboard.blade.php` — Timeline tab + pane,
  tab renumber comments, timeline JS include.

## Test results (2026-09-10)

- `DashboardHealthTest`: 8/8 pass (renders full dashboard incl. timeline).
- `DashboardPendingExtensionsTest`: 7/7 pass.
- `DashboardOverdueInspectionTest`: 7/8 — `test_count_badge_matches_ajax_filter_list`
  FAILS at line 175 (`assertDontSee('On Track')`). Pre-existing, unrelated:
  `SimulateDate` middleware (`bootstrap/app.php` web stack) calls
  `Carbon::setTestNow()` with no args after EVERY request, which wipes the
  test's `travelTo(2026-08-06)` freeze; this test makes two HTTP requests,
  so the second (filter) runs on real today (Sep 2026), past the hardcoded
  Aug-2026 baseline + 7-day grace. Any fix belongs to the middleware/test
  (e.g. preserve an existing test-now), not to this feature — leaving it
  untouched per "expand, don't redesign" rule. Fails identically with or
  without the timeline changes (timeline touches neither `filter()` nor
  time handling).

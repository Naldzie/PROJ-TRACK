# UI / FRONTEND POLISH — Dashboards & Data-Display Pages

CSPC PMU System — phase-ui-polish branch

Status: ✅ Phase A + Phase B COMPLETE (committed & pushed) — Phase C next

Rules: textfiles/active_reference/project_context_and_development_rules.txt (Rule 8/10/11/12)

---

## 1. OBJECTIVE

Polish the UI and front-end user experience, focused on the **dashboards** and
**pages that display data**. The user decided to improve the UI **before** going
back to other feature work.

Scope decisions confirmed with the user (Aug 6, 2026):

- **Wire real data + polish**, BUT **do NOT touch the Calendar and Campus Map
  widgets** for now (they are treated as additional features for later).
- **Keep the 3 separate layouts** (app / stafflayouts / contractorlayouts) — no
  layout consolidation.
- Merge `phase-20-risk-register` to `main` **first**, then branch UI work off a
  clean main (done — see §6).

## 2. CURRENT STATE (verified Aug 6, 2026)

### Real data (good)
- Admin/Staff dashboard **Overview** tab — `admin/dashboard.blade.php` +
  `DashboardController@index` (stats, ongoing-projects table, activity feed).
- Project detail page — Overview, Issues & Risks, Progress, Documents tabs all
  live DB / AJAX.
- Report exports (PDF/Excel) — merged into `main`.

### Fake / hardcoded data (to fix)
- ~~**Contractor dashboard**~~ — ✅ FIXED (Phase A, commit `0f41ec3`): rewritten with
  real data via `app/Http/Controllers/Contractor/DashboardController.php`.
- **Calendar tab** — hardcoded `projectsData` (9 fake projects)
  (`dashboard/partials/calendar-widget.blade.php`). ⬜ leave as-is.
- **Campus Map tab** — hardcoded `mockProjects` + fake coords
  (`dashboard/partials/campus-map-widget.blade.php`). ⬜ leave as-is.
- **`staff/dashboard.blade.php`** — orphaned placeholder (unused; staff render
  `admin/dashboard.blade.php`). → kept for now (user deferred the deletion).

### Structural
- 3 near-identical layouts each with ~200 lines of duplicated inline
  sidebar/topbar CSS. Fixed 260px sidebar (not responsive). Layouts stay
  separate per user decision.

## 3. PHASES

Each phase: test/verify before next (Rule 8), inform user at completion
(Rule 10), log here (Rule 11). All work on `phase-ui-polish`; no merge to main
without explicit approval (Rule 12).

### Phase A — Contractor dashboard: real data (highest value) ✅ DONE

- NEW `app/Http/Controllers/Contractor/DashboardController.php` (per-role
  controller convention) with:
  - Assigned projects (non-archived, scoped to
    `auth()->user()->contractor_id`), eager-loaded contractor/category/latest
    progress.
  - Stats: total assigned / ongoing / completed / planned ("not yet started").
  - Total `approved_budget` across assigned projects.
  - Upcoming deadlines: ongoing sorted by `planned_end_date`, with
    `isOverdue()/isAtRisk()/getDaysRemaining()` + risk color (reuse Project
    helpers).
  - Recent notifications (reuse existing notification query).
- Route: replace the `contractor/dashboard` closure with the controller method.
- View: rewrite `contractor/dashboard.blade.php` — keep the existing visual
  style, replace every hardcoded value with real loops; add empty states.
- TEST `tests/Feature/ContractorDashboardTest.php` — stats correct, only own
  projects, no data leak across contractors.
- ✅ Committed `0f41ec3` + pushed `485b319..0f41ec3`. Full suite green: 377 passed.
- Timeline card replaced by a projects table matching the admin/staff dashboard
  style; deadline days shown as whole numbers.

### Phase B — Admin/Staff dashboard polish (Overview tab only) ✅ DONE

`admin/dashboard.blade.php` + `css/dashboard/*.css`. Calendar + Campus Map tabs
untouched.

- Stat cards link to meaningful destinations (filtered project lists) instead
  of all pointing to the plain projects page. `ProjectController@index` now
  honors `?status=` and `?filter=overdue|atrisk|pending|inspection_overdue`
  (admin/staff); pagination keeps query string.
- Projects index shows a "Showing: …" banner with a Clear-filter link when a
  deep link is active; role-aware subtitle (Staff Overview / Admin Overview);
  stat-card + header + "View all" links use the role-correct route.
- Alert-bar + table row polish (progress bars, deadline coloring, health
  badges) — already in place; AJAX loading/error rows now use the shared
  empty-state styling.
- Empty states: new shared `.db-empty` utility in `dashboard.css`, applied to
  the ongoing-projects table, activity feed, contractor table, and JS
  loading/error rows.
- **NEW "Overdue Inspections" pill** (user-requested, added during Phase B):
  - *Pending Updates* unchanged = ongoing projects with no progress record for
    the current month (cadence signal, date-agnostic).
  - *Overdue Inspections* = ongoing projects with ≥1 baseline month whose
    `planned_date` is past the `notification_overdue_grace_days` window
    (default 7) and no record for that month — mirrors
    `SendNotifications@sendOverdueInspectionReminders` exactly.
  - Shared `overdueInspectionQuery($graceDays)` helper in `DashboardController`
    keeps the count badge and the AJAX list (`type=inspection_overdue`) in sync.
  - Deep-link `?filter=inspection_overdue` added to `ProjectController@index`.
- **Latent bug fixed en route:** `orderByRaw("...NOW()")` in `DashboardController`
  (4 spots) was SQLite-incompatible and ignored the Date Simulator (DB server
  time). Replaced with a bound `now()` parameter — dashboard now works on
  SQLite and respects simulated dates.
- TEST `tests/Feature/DashboardOverdueInspectionTest.php` (8 tests) + updated
  `ContractorDashboardTest` empty-state copy. Full suite green: 385 passed.
- Browser-verified per Rule 8.

### Phase C — Data-display page pass

Browser-walk each page; fix page headers, card styling, tables, status badges,
empty states, button consistency.

- Projects index (`projects/index.blade.php`) — filter/search/sort/pagination;
  verify existing JS filters work.
- Project detail — header, stats-row, tab nav, baseline/progress/issues/risks
  tables + modals.
- Secondary pages — email inbox, warranty, ratings, profile, notifications,
  activity logs (heading/card/empty-state consistency).

### Phase D — Cross-cutting UX (lighter, time permitting)

- Toast/SweetAlert consistency, loading states.
- Light responsive pass (allow fixed sidebar to collapse on small screens)
  WITHOUT merging the 3 layouts.
- Anything risky flagged before proceeding.

## 4. CONSTRAINTS

- Keep the 3 separate layouts.
- Calendar + Campus Map widgets stay as mock placeholders — no changes.
- No-vite convention: CSS/JS in `public/`, linked via `asset()`.
- No new dependencies.

## 5. FILES (anticipated)

NEW:
- `app/Http/Controllers/Contractor/DashboardController.php` ✅
- `tests/Feature/ContractorDashboardTest.php` ✅
- `tests/Feature/DashboardOverdueInspectionTest.php` ✅
- `textfiles/ui_polish_plan.md` (this file)

MODIFIED:
- `routes/web.php` (contractor dashboard route) ✅
- `resources/views/contractor/dashboard.blade.php` ✅
- `resources/views/admin/dashboard.blade.php` ✅
- `public/css/dashboard/dashboard.css` ✅ (`.db-empty` utility)
- `public/js/dashboard.js` ✅ (badge label map)
- `app/Http/Controllers/DashboardController.php` ✅ (overdue-inspection signal + NOW() fix)
- `app/Http/Controllers/ProjectController.php` ✅ (filter=… deep links)
- `resources/views/projects/index.blade.php` ✅ (filter banner)
- Secondary pages per Phase C findings.

DELETED (housekeeping — deferred, user chose to keep for now):
- `resources/views/staff/dashboard.blade.php` (orphaned placeholder)

## 6. SESSION LOG

### 2026-08-06 — Plan created

- Merged `phase-20-risk-register` → `main` (fast-forward, `47a7ddb..8147d49`).
  Full suite green before merge: **372 passed / 1324 assertions**.
- Created `phase-ui-polish` off `main`.
- This plan committed plan-only; no implementation yet.

### 2026-08-06 — Phase A committed & pushed

- Commit `0f41ec3` "contractor dashboard data wiring" — new
  `Contractor\DashboardController`, rewritten `contractor/dashboard.blade.php`
  (real stat cards, projects table, budget, donut, deadlines as whole days,
  activity feed, empty states), route aliased in `routes/web.php`,
  `tests/Feature/ContractorDashboardTest.php`.
- Pushed `485b319..0f41ec3`. Full suite green: **377 passed / 1347 assertions**.

### 2026-08-06 — Phase B completed (committed & pushed with this update)

- Stat-card filtered deep links + projects-index filter banner + role-aware
  subtitle; shared `.db-empty` empty-state utility across tables/feeds/JS.
- NEW **Overdue Inspections** pill (7-day grace, mirrors notification scan):
  `DashboardController@overdueInspectionQuery()` + `type=inspection_overdue`
  AJAX + `?filter=inspection_overdue` deep link + pill in alert bar.
- Fixed latent SQLite/Date-Simulator bug: `orderByRaw(...NOW())` → bound
  `now()` (4 spots in `DashboardController`).
- Tests: `tests/Feature/DashboardOverdueInspectionTest.php` (8 tests);
  updated `ContractorDashboardTest` empty-state copy. Full suite green:
  **385 passed / 1368 assertions**.

---

END OF UI POLISH PLAN

# UI / FRONTEND POLISH — Dashboards & Data-Display Pages

CSPC PMU System — phase-ui-polish branch

Status: 🔄 IN PROGRESS — plan committed (no implementation yet)

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
- **Contractor dashboard** — `contractor/dashboard.blade.php` is a fully
  hardcoded placeholder (844 lines: mock stats, "Jan–Dec 2024" timelines, fake
  budgets, hardcoded donut + feeds). Route is a bare closure
  (`routes/web.php` `Route::get('/dashboard', fn() => view(...))`). ← PRIMARY FIX
- **Calendar tab** — hardcoded `projectsData` (9 fake projects)
  (`dashboard/partials/calendar-widget.blade.php`). ⬜ leave as-is.
- **Campus Map tab** — hardcoded `mockProjects` + fake coords
  (`dashboard/partials/campus-map-widget.blade.php`). ⬜ leave as-is.
- **`staff/dashboard.blade.php`** — orphaned placeholder (unused; staff render
  `admin/dashboard.blade.php`). → delete as housekeeping.

### Structural
- 3 near-identical layouts each with ~200 lines of duplicated inline
  sidebar/topbar CSS. Fixed 260px sidebar (not responsive). Layouts stay
  separate per user decision.

## 3. PHASES

Each phase: test/verify before next (Rule 8), inform user at completion
(Rule 10), log here (Rule 11). All work on `phase-ui-polish`; no merge to main
without explicit approval (Rule 12).

### Phase A — Contractor dashboard: real data (highest value)

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

### Phase B — Admin/Staff dashboard polish (Overview tab only)

`admin/dashboard.blade.php` + `css/dashboard/*.css`. Calendar + Campus Map tabs
untouched.

- Stat cards link to meaningful destinations (filtered project lists) instead
  of all pointing to the plain projects page.
- Alert-bar + table row polish (progress bars, deadline coloring, health
  badges).
- Empty states for the projects table + activity feed.
- Role-aware subtitle (staff vs admin); spacing/typography consistency.
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
- `app/Http/Controllers/Contractor/DashboardController.php`
- `tests/Feature/ContractorDashboardTest.php`
- `textfiles/ui_polish_plan.md` (this file)

MODIFIED:
- `routes/web.php` (contractor dashboard route)
- `resources/views/contractor/dashboard.blade.php`
- `resources/views/admin/dashboard.blade.php`
- `public/css/dashboard/dashboard.css`, `dashboard-widgets.css`
- `resources/views/projects/index.blade.php`
- Secondary pages per Phase C findings.

DELETED (housekeeping):
- `resources/views/staff/dashboard.blade.php` (orphaned placeholder)

## 6. SESSION LOG

### 2026-08-06 — Plan created

- Merged `phase-20-risk-register` → `main` (fast-forward, `47a7ddb..8147d49`).
  Full suite green before merge: **372 passed / 1324 assertions**.
- Created `phase-ui-polish` off `main`.
- This plan committed plan-only; no implementation yet.

---

END OF UI POLISH PLAN

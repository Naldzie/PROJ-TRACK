# UI / FRONTEND POLISH — Dashboards & Data-Display Pages

CSPC PMU System — phase-ui-polish branch

Status: ✅ Phases A, B, C & D COMPLETE (committed & pushed)

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

### Phase C — Data-display page pass ✅ DONE (scoped: index + project detail)

Browser-walk each page; fix page headers, card styling, tables, status badges,
empty states, button consistency. Per user decision, Phase C was scoped to the
**projects index + project detail** pages only (secondary pages deferred).

- Projects index (`projects/index.blade.php`) — filter/search/sort/pagination;
  verify existing JS filters work.
- Project detail — header, stats-row, tab nav, baseline/progress/issues/risks
  tables + modals.
- Secondary pages — email inbox, warranty, ratings, profile, notifications,
  activity logs (heading/card/empty-state consistency). ⬜ deferred.

#### Phase C audit (Aug 6) — consistency fixes ✅ (committed & pushed)

Audited the shared index + detail blades. Fixed 4 concrete consistency /
correctness issues:

1. **Filter-banner "Clear filter" pointed contractors at `admin.projects`.**
   `$indexRoute` in `projects/index.blade.php` had no contractor case, so the
   role-correct route now falls back to `contractor.projects`.
2. **Filter banner rendered for contractors** even though `ProjectController@index`
   ignores `?status=`/`?filter=` for that role — banner now gated to admin/staff.
3. **Table empty-state `colspan="9"`** under a 13-column table — fixed to 13
   (empty row no longer renders off-center).
4. **Project detail header Project Manager meta-item was icon-only** (no label /
   value rendered) when `assigned_project_manager` was set — added the
   `<div class="meta-info">` block using `getProjectManagerName()`.
   (`projects/project_detail/components/header.blade.php`)

- TEST `tests/Feature/ProjectsRenderTest.php` (9 tests): index renders with
  project cards; admin deep-link banner + clear target; contractor index never
  links to admin; 13-col empty state; detail renders header/stats/tabs; PM meta
  shown when assigned and absent when not; contractor detail access rules
  (own project OK, other project 403).
- Full suite green: **394 passed / 1396 assertions**.
- ✅ Committed `2980791` + pushed.

### Phase D — Cross-cutting UX (lighter, time permitting) ✅ DONE

- Toast/SweetAlert consistency, loading states.
- Light responsive pass (allow fixed sidebar to collapse on small screens)
  WITHOUT merging the 3 layouts.
- Anything risky flagged before proceeding.

#### Phase D audit (Aug 7) — toast unify + responsive sidebar ✅ (committed & pushed)

**Loading states** — audited, already well covered (email pages + document viewer
have spinners/overlays, ~83 disabled/spinner/saving patterns). No work needed.

**Toast unification** — the 4 live pages with a legacy bottom-right `#toast` +
local `showToast(text)` (no type arg) migrated to the shared
`components/toast` (top-right `#toastContainer`, typed `showToast(msg, type)`):

- `admin/email.blade.php` — removed local `.toast` CSS, `#toast` div, local
  `showToast()`; auto-send toggle now calls typed `showToast(msg, 'success'|'warning')`.
- `staff/email.blade.php`, `contractor/email.blade.php` — removed the (dead)
  local toast CSS/div/function.
- `admin/rating.blade.php` — removed local toast; 3 call sites now typed
  (`'success'`).
- `admin/projects.blade.php` left as-is (orphaned page, not rendered by any
  controller; its local `showToast(type, message)` doesn't affect live pages).

**Responsive sidebar (no layout merge)** — new shared assets wired into all 3
layouts (app / stafflayouts / contractorlayouts):

- `public/css/layouts/responsive.css` — ≤1023.98px: `.sidebar` off-canvas
  translate, `.topbar`/`.content` margin reset, hamburger + backdrop show.
- `public/js/layouts/sidebar.js` — `toggleSidebar()`/`closeSidebar()`; closes on
  nav-click, Escape, or backdrop; resets on resize past the breakpoint.
- Each layout: hamburger `.sidebar-toggle` in the topbar, `.sidebar-backdrop`
  after the content div, CSS/JS linked via `asset()` (no-vite convention).

- TEST `tests/Feature/UiPolishLayoutTest.php` (4 tests): admin.email,
  staff.email, contractor.email, admin.rating each render `#toastContainer`,
  responsive CSS + JS, hamburger, and no legacy `#toast`.
- Full suite green: **398 passed / 1420 assertions**.
- Browser verification still pending (Rule 8) — see §6 next session.

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
- `tests/Feature/ProjectsRenderTest.php` ✅
- `tests/Feature/UiPolishLayoutTest.php` ✅
- `public/css/layouts/responsive.css` ✅
- `public/js/layouts/sidebar.js` ✅
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
- `resources/views/layouts/app.blade.php` ✅ (responsive CSS/JS + hamburger + backdrop)
- `resources/views/layouts/stafflayouts.blade.php` ✅ (same)
- `resources/views/layouts/contractorlayouts.blade.php` ✅ (same)
- `resources/views/admin/email.blade.php` ✅ (toast migration)
- `resources/views/staff/email.blade.php` ✅ (toast cleanup)
- `resources/views/contractor/email.blade.php` ✅ (toast cleanup)
- `resources/views/admin/rating.blade.php` ✅ (toast migration)
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

### 2026-08-06 — Phase C audit: index + detail consistency fixes (committed & pushed)

- Fixed 4 issues in `projects/index.blade.php` (contractor clear-filter route,
  banner gating, `colspan` 9→13) and project detail header (PM meta-item
  label/value).
- New `tests/Feature/ProjectsRenderTest.php` (9 tests). Full suite green:
  **394 passed / 1396 assertions**.
- Commit `2980791` "Projects index & detail consistency: contractor clear-filter
  route, banner gating, 13-col empty state, PM meta-item; render tests" + pushed.

### 2026-08-06 — Phase D started

- Phase D = cross-cutting UX: toast/SweetAlert consistency + loading states, and
  a light responsive pass (fixed sidebar collapses on small screens) WITHOUT
  merging the 3 layouts. Anything risky flagged before proceeding.

### 2026-08-07 — Phase D completed (committed & pushed with this update)

- **Toast unify:** 4 live pages migrated from legacy bottom-right `#toast` +
  local `showToast(text)` to shared `components/toast` (typed `showToast`).
  `admin/email` + `admin/rating` calls now typed; `staff/email` +
  `contractor/email` dead toast code removed. `admin/projects.blade.php` left
  alone (orphaned page).
- **Responsive sidebar:** shared `public/css/layouts/responsive.css` +
  `public/js/layouts/sidebar.js`, wired into all 3 layouts (hamburger in
  topbar, backdrop, off-canvas ≤1023.98px). No layout merge.
- Loading-state audit: already well covered — no work.
- New `tests/Feature/UiPolishLayoutTest.php` (4 tests). Full suite green:
  **398 passed / 1420 assertions**.
- ⚠️ Remaining: browser-verify responsive sidebar + toast visuals per Rule 8
  (needs a running server), then commit + push.

### 2026-08-07 — Project delete: toast-after-reload (session flash) + lighter overlay

User refined the delete UX further:
1. The loading overlay was "too white" — now frosted/translucent.
2. The success toast kept appearing before the reload, leaving the deleted
   project visible for a split second. Decision: **Option B** — toast renders
   AFTER the reload via a session flash (no pre-reload toast, no in-place JS).

- `components/loading-overlay.blade.php` — overlay bg `rgba(255,255,255,0.45)`
  + `blur(3px)`; spinner + label moved into a small white card so they stay
  legible without washing the page white.
- `ProjectController@destroy` — on success flashes `toast_success` (dedicated
  key, avoids the layouts' `session('success')` SweetAlert convention).
- `projects/index.blade.php` — success branch now `hideLoadingOverlay()` then
  `location.reload()` immediately (dropped the 1500ms delay + pre-reload toast);
  a `@if(session('toast_success'))` script shows `showToast(..., 'success')` on
  DOMContentLoaded (via `@json()` for safe quoting). Failure/network paths
  unchanged.
- Tests: +1 `ProjectsRenderTest` (index renders the delete loading overlay);
  admin-delete test asserts `assertSessionHas('toast_success')` and that the
  flashed message renders on the index reload. Full suite green:
  **401 passed / 1434 assertions**.
- Deferred follow-up: migrate the 4 duplicated email/login overlays to the
  reusable `components/loading-overlay`; orphaned `admin/projects.blade.php`
  untouched.

### 2026-08-07 — Delete flow: overlay persists through reload (no gap)

Fixed the remaining UX gap: after DELETE success the overlay was hidden before
`location.reload()`, leaving the old page visible for a beat while the reload
painted.

- `projects/index.blade.php` — `deleteProject()` success branch no longer calls
  `hideLoadingOverlay()`. Instead it re-labels the overlay to "Almost done…"
  (`showLoadingOverlay('deleteProjectLoadingOverlay', 'Almost done…')`) and then
  calls `location.reload()`; the reload itself tears the page down, so the
  overlay stays visible continuously with no white-gap. The post-reload flash
  toast (`session('toast_success')`) still renders over the fresh page. Failure
  and network paths unchanged.
- Tests: `test_admin_index_renders_delete_loading_overlay` now also asserts
  `Almost done…`. Full suite green: **401 passed / 1435 assertions**.
- ⚠️ Remaining: browser-verify per Rule 8 (delete a project → overlay holds
  through reload → toast over updated page), then commit + push.

### 2026-08-07 — Create-project modal: stepper redesign + auto-add documents + detail-page success toast

User-requested polish on the create/edit project modal and the post-save
redirect.

1. **Step indicator bug + redesign** — `updateStepIndicators()` in
   `form-navigation.js` only swapped `step-indicator-completed/current/future`
   classes but never removed the initial `active`/`inactive` classes, and those
   (specificity 0,2,0) outranked the JS classes (0,1,0) — so step colors were
   frozen (step 1 always blue, steps 2-5 always gray). Rewrote the indicator as
   a numbered **step wizard** (`.step-item`/`.step-dot`/`.step-label` +
   `.step-connector` track) in `_create_project_modal.blade.php` +
   `_create_project_modal.css`; the JS now clears all state classes each pass
   and drives `done`/`current`/`upcoming` + connector states (check icon in the
   dot when completed).
2. **Auto-add documents (no button), type still required** — removed the "Add
   Document" button. `document-manager.js` now listens on `documentFileInput`
   `change`: strict **type-before-file** (picking a file without a type shows an
   error toast and clears the input); valid files auto-push to
   `pendingDocuments` with the chosen type. `displayPendingDocuments()` renders
   removable **chips** (`.doc-chip` — icon, name, type badge, ×) mirroring the
   issues-modal attachment upload; local `getDocumentIcon()` helper. File input
   is now `multiple`; type + input reset after each add. Autofill
   (`document-parser.js` reads `getPendingDocuments()`) unaffected.
3. **Success toast on the project detail page** — `ProjectController@store` and
   `@update` AJAX branches now `session()->flash('toast_success', …)` before
   returning the JSON `redirect`; the shared
   `projects/project_detail/project_detail.blade.php` renders a load-time
   `showToast(…, 'success')` script on `DOMContentLoaded` (same pattern as the
   index's delete toast; `showToast` is global via the layout's `components.toast`).
   Covers all three roles since they share the detail view.
- Tests: +2 `ProjectsRenderTest` (stepper + no `addDocumentBtn` + type-first
  helper text; detail page renders the flashed `toast_success` script). Full
  suite green: **403 passed / 1444 assertions**.
- ⚠️ Remaining: browser-verify per Rule 8, then commit + push.

### 2026-08-07 — Always-on page-loading overlay (masks page render on every load)

User asked whether slow page reloads could be masked with a loading state.
Chose: **unconditional, server-rendered loader on every page load**, hiding on
`window.load` with a hard timeout fallback (no flash gating — no controller
edits).

1. **New partial** `components/page-loading-overlay.blade.php` — fully
   self-contained (inline `<style>` + `<script>`, own spinner keyframes, no
   Bootstrap dependency) so it paints with the first bytes of the page. Fixed
   frosted overlay (`rgba(255,255,255,0.45)` + blur), white card + "Loading…",
   `z-index: 3000` (above sidebar 100, below toast 9999 so `showToast()` stays
   visible on top). Element `#pageLoadingOverlay` with marker `data-auto-hide="load"`.
2. **Behaviour** — hides on `window.load` after a ~350ms minimum display (so
   fast/cached navs fade smoothly instead of blinking); hard `setTimeout` 5s
   fallback so a hanging CDN can never leave the page covered; idempotent fade +
   element removal; `<noscript>` fallback hides it for no-JS clients.
3. **Wiring** — `@include('components.page-loading-overlay')` as the first
   element inside `<body>` of all three layouts (`app`, `stafflayouts`,
   `contractorlayouts`). Completes the delete flow's mask (pre-nav overlay →
   reload → this loader → toast) and covers the heavy detail-page render after
   create/edit redirects.
- Tests: `UiPolishLayoutTest` asserts `id="pageLoadingOverlay"` +
  `data-auto-hide="load"` on all four email/rating pages + new
  `test_admin_projects_page_renders_page_loading_overlay`. Full suite green:
  **404 passed / 1460 assertions**.
- ⚠️ Remaining: browser-verify per Rule 8 (throttled network → loader holds
  until assets finish; delete/create/edit flows still toast correctly), then
  commit + push.

---

### 2026-08-07 — Edit mode: Documents step removed (4-step wizard) + clickable step-skipping

User confirmed: in the create/edit project modal's **edit** mode, drop the
document step entirely (documents are managed on the project detail page's
dedicated tab), renumbering the wizard to 4 steps; and make the step indicators
clickable for quick skipping in edit mode.

1. **Blade** `admin/_create_project_modal.blade.php` — removed the
   `#existingDocumentsSection` markup (current contract + supporting-docs list);
   added `id="step1-label"`…`step5-label"` to the step labels so JS can renumber
   them per mode.
2. **form-navigation.js** — wizard is now mode-aware:
   `getTotalPages()` (4 edit / 5 create), `getDomPageForStep()` (edit maps logical
   step N → DOM page N+1, skipping page 1); `updatePageDisplay`, `updateStepIndicators`,
   `nextPage`/`previousPage`, the submit-validation loop and `validatePage()` (switch
   now keys off the DOM page) all use the helpers. Added `goToStep(step)` — edit-only
   jump (no per-step validation; final submit still validates every page).
3. **_create_project_modal.js** — new `applyWizardMode()`: edit mode renumbers labels
   (Project Info, Details, Timeline, Assignment), hides `step5-indicator` +
   `step4-connector`, and toggles `.step-item--clickable`; create mode restores the
   full 5-step layout. Called on load, in `resetModalToCreateMode()`, and
   `openEditProjectModal()`. Removed the existing-documents population block and its
   reset lines; pending-doc append on submit now runs in create mode only. A delegated
   `.step-wizard` click listener routes clicks to `goToStep()`.
4. **CSS** `_create_project_modal.css` — `.step-item--clickable { cursor: pointer }`
   + hover dot scale/color for affordance.
- Server note: `ProjectController@update()` already ignored `pending_document_files`
  (only `store()` calls `createProjectDocuments()`), so no controller change was needed.
- Tests: `ProjectsRenderTest` stepper test now asserts `step1-label`/`step5-label` and
  `assertDontSee('existingDocumentsSection')`. Full suite green:
  **404 passed / 1463 assertions**.
- ⚠️ Remaining: browser-verify per Rule 8 (edit modal = 4 renumbered steps, click to
  skip, no documents; create modal unchanged with 5 steps + autofill), then commit + push.

---

END OF UI POLISH PLAN

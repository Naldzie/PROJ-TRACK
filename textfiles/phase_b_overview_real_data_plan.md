# PHASE B — Overview Real Data

CSPC PMU System — Core Project Monitoring (core_monitoring_completion_plan.md Phase B)

Status: ✅ IMPLEMENTED — all steps complete, tests green (2026-08-02)

Branch: `core/overview-real-data` (created from `main` @ `7d27678`)

Date: August 2, 2026

Parent plan: textfiles/core_monitoring_completion_plan.md (Phase B, §5)
Rules: textfiles/active_reference/project_context_and_development_rules.txt (Rule 8/10/11/12)
Note: Phase B is intentionally INDEPENDENT of Phase A (attachments) — it bases on
      `main`, per the parent plan's branch table §9. The attachments migration
      lives on `core/attachments-infra` @ `4814ac2`, unmerged.

---

## 1. OBJECTIVE

Replace the hardcoded mockup cards on the Overview tab (`overview.blade.php`)
with real, database-backed data:

- **Issues & Risks** — top 3 open issues by severity + total open count → link
  to the Issues tab.
- **Notifications & Alerts** — derived from real signals (no new table).
- **Documents** — latest 3 from `$data['documents']` + `total_documents`.
- **Recent Activity** — real project-filtered timeline from `activity_logs`.

Remove `$issueMockups` / `$alertMockups` / `$documentMockups`, the static
timeline, and the `Mockup preview` / `Mockup timeline` labels.

Supporting change: add a nullable `project_id` to `activity_logs` and populate
every project-scoped `ActivityLog::create(...)` call site so the Recent Activity
card is genuinely project-filtered.

---

## 2. CURRENT STATE & PATTERNS (verified 2026-08-02)

### The view
`resources/views/projects/project_detail/components/tabs/overview.blade.php`:
- Mockup arrays at lines 87–104 (`$issueMockups`, `$alertMockups`,
  `$documentMockups`).
- `Mockup preview` / `Mockup timeline` labels at the four card headers
  (lines 238, 250, 273, 285) and a static timeline (287–290).
- Already-really cards to keep: Project Information, Monthly Progress Snapshot,
  Baseline Highlights.
- Included from `project_detail.blade.php:61` with
  `['project' => $project, 'data' => $data, ...]`.

### The data pipeline
- `ProjectController::show()` → `ProjectDetailService::prepareProjectData($project)`.
- `$data` already contains: `overview`, `progress`, `charts`, `status`,
  `ai_prediction`, `trends`, `documents` (Eloquent collection, created_at desc,
  documentType loaded), `documents_by_type`, `total_documents`.
- `$data` does NOT contain issues, alerts, or recent activity — Phase B adds them.
- `ProjectOverviewService` already exists and is injected into
  `ProjectDetailService` — Phase B EXTENDS it (no new service class).
- `ProjectDocumentService::getDocumentsComplianceData()` supplies the documents.

### activity_logs
- Schema (`2026_02_16_093919_create_activity_logs_table.php` +
  `2026_06_21_010822_add_visibility_to_activity_logs_table.php`):
  `id`, `user_id` FK, `action` string, `description` text nullable,
  `ip_address` nullable, `timestamps`, `visibility` enum(all/admin_only).
  **No `project_id`.**
- `ActivityLog` model fillable: `user_id, action, description, ip_address,
  visibility`; has `user()` and `scopeVisibleTo($role)` (admins see all;
  others only `visibility = 'all'`).
- `ActivityLogController::index()` does NOT apply `visibleTo` today.

### Project-scoped ActivityLog::create call sites (all have `$project` or a
### model carrying `project_id` in scope)
| File | Lines | Action(s) |
|---|---|---|
| `app/Http/Controllers/ProjectProgressController.php` | 53, 178, 275, 401, 493 | Create/Edit/Delete/Record Monthly Progress, Project Auto-Completed |
| `app/Http/Controllers/DocumentController.php` | 167 | Delete Document (use `$document->project_id`) |
| `app/Http/Controllers/BaselineScheduleController.php` | 217, 370, 481, 599, 896, 963 | Create/Set Up/Update Baseline, Carry Over, Adjust, Delete |
| `app/Http/Controllers/ProjectActivityController.php` | 109, 149, 230 | Add/Delete/Update Checklist Item |
| `app/Models/Project.php` booted() | 371, 397, 437 | Create/Update/Delete Project (use `$project->id`) |

Non-project-scoped calls to LEAVE ALONE: login/logout/edit-user
(`ActivityLogController`, `AuthController`, `LogSuccessfulLogin`,
`AppServiceProvider` login listener), `ContractorController`, `StaffController`,
`ProfileController`.

### ProjectExtensionController (missing logs — summary_of_next_work.txt §6)
`app/Http/Controllers/Admin/ProjectExtensionController.php` has NO
`ActivityLog::create` at all. `store()` submits a pending request; `update()`
approves/rejects; `destroy()` deletes. §6 flags store()/update(); destroy()
added too for a complete audit trail. Add all three WITH `project_id`.

### Data for alerts
- `Issue` model: `SEVERITIES = [Low, Medium, High, Critical]`, statuses
  Open/In Progress/Resolved/Closed, `resolved_at`, `scopeUnresolved()`
  (Open + In Progress), `scopeAging(30)`.
- `ProjectExtension`: `status` enum pending/approved/rejected + `scopePending()`.
- Baselines use `month_label` (Y-m) + `planned_date`; progress history uses
  `month_label` too → "next pending inspection month" = first baseline by
  planned_date whose month_label has no progress record.
- `total_documents` already in `$data`.

### Tests (convention)
- `tests/Feature/Services/AI/ProgressDeleteGuardTest.php` pattern: direct
  controller/service invocation, `User::create([...])` (NOT `User::factory()` —
  users table has no `email_verified_at`), RefreshDatabase, sqlite :memory:.
- Route-based authenticated requests DO NOT authenticate in this test env
  (auth middleware 401s even after actingAs — repo-wide; see Phase A log §8).
  Tests invoke services/controllers directly.

---

## 3. CONFIRMED DECISIONS

1. **Extend the existing `ProjectOverviewService`** — add `getRecentActivity()`,
   `getAlerts()`, `getOpenIssues()`. No new service class.
2. **Wire via `ProjectDetailService::prepareProjectData()`** — the view reads
   `$data['recent_activity']`, `$data['alerts']`, `$data['open_issues']`
   (keeps the Blade view dumb).
3. **Alerts are derived, no new table** — 4 types:
   - **Next pending inspection** — first baseline month with no progress record
     (shown until recorded; suppressed for Completed projects).
   - **Open / aging issues** — open count + worst severity; aging (>30 days) count.
   - **Pending extension requests** — `status = 'pending'` count.
   - **Document-coverage reminder** — shown when `total_documents == 0`.
4. **`activity_logs.project_id`** — nullable FK → projects, `nullOnDelete`,
   indexed; added to `$fillable`; `project()` relation added.
5. **Populate the ~17 project-scoped call sites** (table above) including the
   `Project` model's own booted() hooks; leave global/user-management logs alone.
6. **ProjectExtensionController** — add `ActivityLog::create` in store()/update()/
   destroy() WITH `project_id` (fixes summary §6).
7. **Recent Activity card** — latest 6 project logs, newest first, joined with
   user name; **applies `scopeVisibleTo(role)`** so non-admins never see
   `admin_only` rows (consistent with the visibility design).
8. **Severity ordering** — Critical=4, High=3, Medium=2, Low=1 (used to rank the
   Issues card and worst-severity alert).

---

## 4. IMPLEMENTATION STEPS (ordered; test each before the next — Rule 8)

### Step 1 — Migration + model

NEW: `database/migrations/<ts>_add_project_id_to_activity_logs_table.php`
- `$table->foreignId('project_id')->nullable()->after('user_id')
   ->constrained('projects')->nullOnDelete();`
- `$table->index('project_id');`
- `down()`: drop foreign+index, drop column.

MODIFIED: `app/Models/ActivityLog.php`
- Add `'project_id'` to `$fillable`.
- Add `project()` → `belongsTo(Project::class)`.

### Step 2 — Service: ProjectOverviewService

MODIFIED: `app/Services/ProjectOverviewService.php`

- `getOpenIssues(Project): array` — unresolved issues
  (`->whereIn('status', ['Open','In Progress'])`), ordered by severity desc then
  created_at asc, take 3. Returns items `{ id, issue_name, severity, status,
  created_at, days_open, badge_color }` + a `total_open` count + `worst_severity`.
  Also count aging issues (`created_at <= now()->subDays(30)`).

- `getAlerts(Project): array` — derived list (each
  `{ type, title, meta, color }`):
  - If not Completed: find first baseline (by planned_date) with no
    `progressHistory` row for its `month_label` → alert
    "Inspection pending for {month}" (orange).
  - Open issues → alert "{n} open issue(s) · {worst_severity}" (red if any
    Critical/High, else orange); if aging >0 append "· {a} aging".
  - Pending extensions count → "extension request(s) awaiting approval" (blue).
  - `documents()->count() == 0` → "No documents uploaded yet" (purple).
  - Empty collection if no signals.

- `getRecentActivity(Project): Collection` — `ActivityLog::with('user')`
  ->where('project_id', $project->id)
  ->visibleTo(auth()->user()?->role ?? 'admin')  // admins see all
  ->latest('created_at')->take(6)->get().

### Step 3 — Wire into ProjectDetailService

MODIFIED: `app/Services/ProjectDetailService.php`
- In `prepareProjectData()` return array add:
  `'recent_activity' => $this->overviewService->getRecentActivity($project)`,
  `'alerts'          => $this->overviewService->getAlerts($project)`,
  `'open_issues'     => $this->overviewService->getOpenIssues($project)`.

### Step 4 — Populate project_id across call sites

MODIFIED files (add `'project_id' => $project->id,` — or model's project_id):
- `ProjectProgressController.php` — 5 calls (L53, L178, L275, L401, L493).
- `DocumentController.php` — delete() → `'project_id' => $document->project_id`.
- `BaselineScheduleController.php` — 6 calls (L217, L370, L481, L599, L896, L963).
- `ProjectActivityController.php` — 3 calls (L109, L149 use `$project->id`;
  L230 use `$activity->project_id`).
- `Project.php` booted() — 3 calls (created/updated/deleted → `$project->id`).

MODIFIED: `Admin/ProjectExtensionController.php` — ADD ActivityLog::create:
- `store()` → action 'Request Extension', description with requested days,
  visibility 'all'.
- `update()` → action 'Approve Extension' or 'Reject Extension' (+ rejection
  reason if present), visibility 'all'.
- `destroy()` → action 'Delete Extension', visibility 'admin_only'.
  All with `'project_id' => $project->id`.

### Step 5 — View: overview.blade.php

MODIFIED: `resources/views/projects/project_detail/components/tabs/overview.blade.php`
- Remove `$issueMockups` / `$alertMockups` / `$documentMockups` + the static
  timeline + "Mockup preview"/"Mockup timeline" labels.
- **Issues & Risks** card: iterate `$data['open_issues']['items']`; each row
  shows `issue_name`, severity badge (colored), `X days open`; footer link
  "View all {total_open} open issues →" that switches to the `#issues` tab.
- **Notifications & Alerts** card: iterate `$data['alerts']` — colored dot,
  title, meta. Empty-state "No active alerts".
- **Documents** card: iterate first 3 of `$data['documents']` (doc-icon,
  `document_name`, size via `file_size` accessor + created_at date); footer
  "All {total_documents} documents →" → `#documents` tab.
- **Recent Activity** card: iterate `$data['recent_activity']` — colored dot
  (var by action), `<strong>{{ action }}</strong> — {{ description }}`,
  `{{ user->name }} · {{ created_at->diffForHumans() }}`. Empty-state "No
  activity yet".

### Step 6 — Tests

NEW: `tests/Feature/OverviewTest.php` (direct service invocation; convention):
1. `getOpenIssues_returns_top3_by_severity_plus_total`
2. `getOpenIssues_counts_aging_issues`
3. `getAlerts_next_pending_month_when_baseline_unrecorded`
4. `getAlerts_suppressed_for_completed_project`
5. `getAlerts_open_issues_with_worst_severity`
6. `getAlerts_pending_extensions`
7. `getAlerts_document_coverage_reminder_when_zero_documents`
8. `getAlerts_empty_when_no_signals`
9. `getRecentActivity_returns_latest_six_project_scoped`
10. `activity_logs_project_id_populated_on_progress_record` — drive
    `ProjectProgressController::recordMonthlyProgress()` directly, assert the
    created ActivityLog has the project_id.
11. `overview_view_has_no_mockup_strings` — `view()` render, assert
    `doesntSee('Mockup')` + sees real card titles.

### Step 7 — Verification

- `php -l` on every new/changed PHP file.
- `php artisan test --filter=OverviewTest` — all green.
- Full suite `php artisan test` — no regressions (baseline after Phase A:
  211 passed / 735 assertions; this branch = main-based, so baseline is
  198 passed / 692 assertions).
- `php artisan route:list` sanity (no route changes).

---

## 5. FILES TOUCHED

NEW:
- `database/migrations/<ts>_add_project_id_to_activity_logs_table.php`
- `tests/Feature/OverviewTest.php`
- `textfiles/phase_b_overview_real_data_plan.md` (this file)

MODIFIED:
- `app/Models/ActivityLog.php` (fillable + project relation)
- `app/Services/ProjectOverviewService.php` (3 new methods)
- `app/Services/ProjectDetailService.php` (wire 3 keys into `$data`)
- `app/Http/Controllers/ProjectProgressController.php` (5 call sites)
- `app/Http/Controllers/DocumentController.php` (1 call site)
- `app/Http/Controllers/BaselineScheduleController.php` (6 call sites)
- `app/Http/Controllers/ProjectActivityController.php` (3 call sites)
- `app/Http/Controllers/Admin/ProjectExtensionController.php` (+3 log calls)
- `app/Models/Project.php` (3 booted() call sites)
- `resources/views/projects/project_detail/components/tabs/overview.blade.php`

---

## 6. OPEN QUESTIONS / NOTES

1. **Recent Activity visibility** — applies `scopeVisibleTo(role)` (decision 7).
   Note `ActivityLogController::index()` currently does NOT apply the scope; the
   admin page shows everything. The overview card is stricter for non-admins.
2. **`visibility` default** — new ProjectExtension logs follow the codebase
   convention (store/update 'all', delete 'admin_only').
3. **Alert for past unrecorded months** — the "next pending" alert stays until
   the month is recorded, even if it is already past (it IS overdue, which the
   UI should surface).

---

## 7. PROCESS NOTES

- Rule 12: work only on `core/overview-real-data`; do NOT merge to main without
  explicit user approval.
- Rule 8: test after each step (Step 1 → 7 in order).
- Rule 10: inform the user after the phase completes.
- Rule 11: log progress in §8 as each step lands.

---

## 8. SESSION / CHANGES LOG

(Chronological log of what is built and any bugs found — append as the phase is
implemented.)

### 2026-08-02 — Plan created
- Branch `core/overview-real-data` created from main @ 7d27678.
- Verified Phase A is intact on `core/attachments-infra` @ 4814ac2 (independent
  branch; this phase intentionally does not include it).
- Plan written (this file); committed as plan-only before implementation.

### 2026-08-02 — Implementation complete (uncommitted until verified)
- **Migration** `2026_08_02_000002_add_project_id_to_activity_logs_table.php`:
  nullable `project_id` FK → projects (`nullOnDelete`) + index. Down: drops
  index, FK, column.
- **`ActivityLog` model**: `project_id` added to `$fillable`; `project()`
  belongsTo relation added.
- **`ProjectOverviewService`**: added `severityOrder()`, `severityColor()`,
  `getOpenIssues()` (top 3 unresolved by severity desc then newest; returns
  `total_open`, `worst_severity`, `aging_count` (≥30 days), `items` with
  `days_open` + `badge_color`), `getAlerts()` (derived signals: next pending
  baseline month when project not Completed, open/aging issues, pending
  extension requests, document-coverage reminder when zero documents),
  `getRecentActivity()` (latest 6 project-scoped logs, newest first, joined
  user, `visibleTo(role)`).
- **`ProjectDetailService::prepareProjectData()`**: now returns `recent_activity`,
  `alerts`, `open_issues` keys.
- **`project_id` populated** at all project-scoped log call sites:
  `ProjectProgressController` (record/edit/delete progress), `DocumentController`
  (delete), `BaselineScheduleController` (store/update/delete), 
  `ProjectActivityController` (record/edit/delete activity), `Project::booted()`
  (created/updated/deleted).
- **`ProjectExtensionController`**: added ActivityLog calls — store: "Approve
  Extension" (admin auto-approval) / "Request Extension" (staff); update:
  resubmit/update/approve/reject (appends rejection reason); destroy: "Delete
  Extension" (`admin_only`). Closes the extension-audit gap noted in
  `summary_of_next_work.txt`.
- **`overview.blade.php`**: removed `$issueMockups`, `$alertMockups`,
  `$documentMockups`, static timeline, and "Mockup" labels; Issues & Risks,
  Notifications & Alerts, Documents, and Recent Activity cards now render real
  data with empty states; footer buttons delegate to Issues/Documents tabs.
- **Bugs found & fixed**: Laravel `sortByDesc([fn, fn])` treats each closure as
  a 2-arg comparator — replaced with a single value-retriever closure returning
  a composite key `[severityOrder, created_at]`. `Issue`/`ActivityLog` do not
  allow `created_at` via mass assignment — tests use `forceFill()`.
- **Verification**: `php -l` clean on all 11 changed files; new
  `tests/Feature/OverviewTest.php` (14 tests / 36 assertions) passing; full
  suite green at **212 passed / 728 assertions** (baseline was 198/692).
  Committed as `6c841d6` (Phase B implementation).

---

END OF PHASE B PLAN

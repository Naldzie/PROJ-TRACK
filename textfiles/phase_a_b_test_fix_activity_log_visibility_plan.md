# PHASE A/B TEST FIX — Activity Log Visibility (Dashboard vs Overview)

CSPC PMU System — Additional fix discovered during manual/combined testing of
Phase A (attachments) and Phase B (overview real data).

Status: PLAN ONLY (uncommitted implementation)

Branch: `integration/attachments-and-overview` (combined Phase A + B tree)

Date: August 2, 2026

Parent plans:
- textfiles/phase_a_attachments_infrastructure_plan.md
- textfiles/phase_b_overview_real_data_plan.md
Rules: textfiles/active_reference/project_context_and_development_rules.txt
       (Rule 8/10/11/12)

---

## 1. OBJECTIVE

Make the two activity-log feeds respect the team's intent now that staff see
project-scoped activity:

- **Overview feed** (project-scoped, `getRecentActivity()`): staff SHOULD see
  deletions inside the project they are viewing — "who deleted what on this
  project".
- **Dashboard feed** (global, `DashboardController@index`): staff should NOT see
  routine deletions or admin-housekeeping entries. Every `Delete …` entry is
  low-signal on a global feed for staff.

Decisions confirmed by the client proxy (2026-08-02):
1. Hide `Delete …` actions from the dashboard feed **for staff only** — admins
   keep seeing everything there.
2. Staff dashboard keeps: `Login`, `Logout`, `Create Project`, `Delete Project`.
   Staff dashboard hides: `Edit User`, `Password Change`.
3. Fix the Phase A gap: `Upload/Delete Attachment` logs have **no `project_id`**,
   so they never appear in the Overview Recent Activity.

---

## 2. CURRENT STATE (verified 2026-08-02)

### visibility column
- Schema: `2026_06_21_010822_add_visibility_to_activity_logs_table.php`
  `enum('all','admin_only')` **default `all`**.
- `ActivityLog::scopeVisibleTo($role)` (`app/Models/ActivityLog.php:35`):
  admins → everything; everyone else → `where('visibility', 'all')`.

### Both feeds apply scopeVisibleTo today
- Dashboard: `DashboardController@index` (`:79`) — global, latest 6.
- Overview: `ProjectOverviewService::getRecentActivity()` (`:330`) — project-scoped,
  latest 6, `visibleTo(auth()->user()?->role ?? 'admin')`.

### Action inventory + visibility (as stored)
Project-data updates (`all` → visible to staff in dashboard AND overview):
- Progress: Create / Edit / Record Monthly Progress, Project Auto-Completed
  (`ProjectProgressController:53,179,404,497`)
- Baseline: Create / Set Up / Update Baseline, Adjust Baseline, Carry Over
  Activity (`BaselineScheduleController:217,371,483,900,602`)
- Checklist: Add / Update Checklist Item (`ProjectActivityController:109,232`)
- Extension: Request / Approve / Reject / Resubmit / Update Extension
  (`ProjectExtensionController:83,245,336` — dynamic action strings)
- Upload Document (`DocumentController:76`), Upload Attachment
  (`AttachmentController:87` — **no project_id**)

Deletions (currently `admin_only` → hidden from staff everywhere):
- Delete Progress Record (`ProjectProgressController:277`)
- Delete Baseline Schedule (`BaselineScheduleController:968`)
- Delete Checklist Item (`ProjectActivityController:150`)
- Delete Extension (`ProjectExtensionController:399`)
- Delete Project (`Project.php:439` — project_id nulled by nullOnDelete)
- Delete Document (`DocumentController:179` — `all`, project_id set)
- Delete Attachment (`AttachmentController:140` — `all`, **no project_id**)

System/global (no project_id):
- Login (all: `AppServiceProvider:33`, `ActivityLogController:52`; admin_only:
  `LogSuccessfulLogin:25`), Logout (all), Edit User (all), Password Change
  (all), Create Project (all: `Project.php:371`, legacy `ActivityLogController:119`)
- staff_created/enabled/disabled, role_changed (`StaffController:86,158,124`),
  contractor_created/enabled/disabled (`ContractorController:66,102`) — all
  `admin_only`

---

## 3. DESIGN

Introduce a **dashboard-specific scope** on `ActivityLog` so the two feeds can
differ without changing the shared `visibleTo` semantics used by the overview.

```
scopeForDashboard($query, $role):
    $query->visibleTo($role)
    if ($role !== 'admin'):
        where NOT (action LIKE 'Delete%' AND action != 'Delete Project')
        and action NOT IN ('Edit User', 'Password Change')
```

Because the overview keeps `scopeVisibleTo`, deletions must flip from
`admin_only` to `all` at their call sites so staff see them in the overview.
The dashboard filter then removes them from the staff dashboard feed again.

| Entry | Overview (staff) | Dashboard (staff) | Dashboard (admin) |
|---|---|---|---|
| All project-data updates (A-group) | ✅ | ✅ | ✅ |
| Delete Progress/Baseline/Checklist/Extension | ✅ (after flip to all) | ❌ (filtered) | ✅ |
| Delete Document / Delete Attachment | ✅ (already all) | ❌ (filtered) | ✅ |
| Delete Project | n/a (project_id null) | ✅ (flip to all + allowed) | ✅ |
| Login / Logout / Create Project | n/a (global) | ✅ | ✅ |
| Edit User / Password Change | n/a (global) | ❌ (filtered) | ✅ |
| staff/contractor management | n/a | ❌ (admin_only) | ✅ |

`Delete Project` becomes `all` so the staff dashboard can show it (it never
appears in any overview because `project_id` is nulled on deletion).

---

## 4. IMPLEMENTATION STEPS

1. **`app/Models/ActivityLog.php`** — add `scopeForDashboard($query, $role)`:
   ```php
   public function scopeForDashboard($query, $role)
   {
       $query->visibleTo($role);

       if ($role !== 'admin') {
           $query->where(function ($q) {
               $q->where('action', 'not like', 'Delete%')
                 ->orWhere('action', 'Delete Project');
           })->whereNotIn('action', ['Edit User', 'Password Change']);
       }

       return $query;
   }
   ```

2. **`app/Http/Controllers/DashboardController.php`** — replace
   `->visibleTo(auth()->user()->role)` with
   `->forDashboard(auth()->user()->role)` (`:80`). Update stale comment `:76-78`.

3. **Flip deletion visibility `admin_only` → `all`** so staff see them in the
   Overview:
   - `ProjectProgressController:283` (Delete Progress Record)
   - `BaselineScheduleController:974` (Delete Baseline Schedule)
   - `ProjectActivityController:156` (Delete Checklist Item)
   - `ProjectExtensionController:405` (Delete Extension)
   - `Project.php:445` (Delete Project)

4. **Fix Phase A attachment gap** — add `project_id` to:
   - `AttachmentController:87` (Upload Attachment — `$attachment->project_id`)
   - `AttachmentController:140` (Delete Attachment — `$attachment->project_id`)

5. **`resources/views/admin/dashboard.blade.php:236-240`** — update stale
   comment ("staff never see 'admin_only' entries (deletions, user edits, etc)")
   to describe the dashboard-specific filtering.

6. **Tests** — new `tests/Feature/ActivityVisibilityTest.php`:
   - Overview: staff user's `getRecentActivity()` includes a `Delete Progress
     Record` log for their project (project-scoped, visibility `all`).
   - Dashboard scope: staff excluded from `Delete%` except `Delete Project`;
     excluded from `Edit User` and `Password Change`; includes `Login`,
     `Create Project`, `Delete Project`, and a project-data update.
   - Dashboard scope: admin sees everything (incl. `Delete Progress Record`,
     `Edit User`).
   - Attachment: `AttachmentController::upload()` log carries `project_id`.

7. **Verify** — `php artisan test` (full suite green); `php -l` on changed files.

---

## 5. RULES / CONSTRAINTS
- Rule 12: no merge to `main` without explicit approval. This work stays on
  `integration/attachments-and-overview`.
- `scopeVisibleTo` semantics are preserved — only the dashboard feed changes.
- No changes to the admin-only `/activity-logs` page
  (`ActivityLogController::index` already shows everything).

---

## 6. SESSION / CHANGES LOG

### 2026-08-02 — Plan created
- Plan written (this file) during combined Phase A/B frontend-testing review.
- Client-proxy decisions recorded (§1): staff dashboard hides deletions except
  Delete Project; hides Edit User/Password Change; attachment project_id fixed.

### 2026-08-02 — Implementation complete
- `ActivityLog::scopeForDashboard($role)` added — `visibleTo` + (non-admin)
  exclude `Delete%` except `Delete Project`, exclude `Edit User` / `Password
  Change`. Overview feed keeps `scopeVisibleTo`, so semantics preserved.
- `DashboardController@index` now uses `->forDashboard(role)` (`:80`).
- Deletion visibility flipped `admin_only` → `all` for staff-visible Overview:
  Delete Progress Record, Delete Baseline Schedule, Delete Checklist Item,
  Delete Extension (`ProjectProgressController:283`, `BaselineScheduleController:974`,
  `ProjectActivityController:156`, `ProjectExtensionController:405`).
- `Delete Project` (`Project.php:439`) flipped to `all` for the staff dashboard.
- **Latent bug fixed**: Project has no SoftDeletes, so the `deleted` event fires
  after the row is gone — inserting `project_id => $project->id` would violate
  the FK in MySQL and break project deletion. Now logs with `project_id => null`
  (audit trail survives, matching `nullOnDelete`).
- Phase A gap fixed: `Upload Attachment` / `Delete Attachment` logs now carry
  `project_id` (`AttachmentController:87`, `:140`) so they appear in Overview.
- Stale comment updated in `dashboard.blade.php` Recent Activity block.
- Verification: `php -l` clean on all 9 changed files; new
  `tests/Feature/ActivityVisibilityTest.php` (7 tests / 23 assertions) passing;
  full suite green at **233 passed / 798 assertions**. Committed as `42218bc`.

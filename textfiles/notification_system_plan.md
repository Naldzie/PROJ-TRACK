# NOTIFICATION SYSTEM — In-App Notifications (Gaps #12)

CSPC PMU System — textfiles/gaps_and_features_missing.txt item #12

Status: 📋 PLANNED — not started (branch `core/notification-system` created from `main` @ `f2598aa`; plan-only commit follows)

Branch: `core/notification-system` (created from `main` @ `f2598aa`)

Date: August 4, 2026

Rules: textfiles/active_reference/project_context_and_development_rules.txt (Rule 8/10/11/12)

---

## 1. OBJECTIVE

Add a **database-backed in-app notification system** so PMU admins, staff, and contractors
receive persistent, per-user notifications — with an unread badge + dropdown in the topbar
of all three role layouts and a full "Notifications" page with mark-read / mark-all-read.

Covered notification types (all four, per user confirmation):

- **Deadline reminders** — project `planned_end_date` within 30/14/7 days, or overdue.
- **Overdue inspection alerts** — baseline month whose `planned_date` has passed with no
  progress record.
- **Extension approval/rejection** — staff submit / admin approve / admin reject.
- **Issue created/assigned** — new issue on a project (all severities, per user confirmation).

Design pillar: **Laravel 12 native database notifications**. `User` already uses the
`Notifiable` trait (app/Models/User.php:11); the `notifications` table and all notification
classes are missing and will be created. The `jobs` table already exists
(2026_04_10_110748_create_jobs_table.php) but is unused by any feature — this feature does
**not** require the queue (notifications are written synchronously during the scan/request).

Deduplication: every notification carries a **tracking key** stored in its `data` payload
(e.g. `type + project_id + bucket` for reminders, `type + project_id + month_label` for
inspection alerts, `type + project_id + extension_id` for extensions, `type + project_id +
issue_id` for issues). The generator checks for an existing notification with the same key
before inserting, so daily scans escalate (30→14→7→overdue) without re-firing, and no new
tracking table is required.

Out of scope: email/push delivery. This phase is in-app only (gaps item scope). Email
reminders could reuse the existing AIReportSendingService later if requested.

---

## 2. CURRENT STATE & PATTERNS TO MIRROR (verified 2026-08-04)

Codebase study completed. Everything below was verified on disk.

| Concern | Reference | Pattern |
|---|---|---|
| User notifiable | `app/Models/User.php` | `use HasFactory, Notifiable;` (line 11) — ready for `->notifications()`, `->unreadNotifications()`, `notify()` |
| Roles | `app/Models/User.php` | `role` ∈ `admin`/`staff`/`contractor`; `admin()`/`staff()`/`contractor()` hasOne profiles; `display_name` accessor |
| Admin profile | `app/Models/Admin.php` | `full_name` accessor, `user()` belongsTo |
| Staff profile | `app/Models/Staff.php` | `user()` belongsTo |
| Contractor profile | `app/Models/Contractor.php` | `user()` belongsTo; `projects()` hasMany — so `project->contractor->user` resolves a project's contractor user |
| Project | `app/Models/Project.php` | `planned_end_date`, `status` ('Ongoing','Completed'), `contractor()` belongsTo, `baselineSchedules()`, `progressHistory()`, `extensions()`, `issues()`; `whereIn('status', ['Ongoing','Completed'])` pattern used in SendMonthlyAiReports |
| Baseline | `app/Models/ProjectBaselineSchedule.php` | `planned_date`, `month_label`; `progressHistory()` hasMany |
| Progress | `app/Models/ProjectProgressHistory.php` | per-month records; `month_label`; recorded via `ProjectProgressController::recordMonthlyProgress()` |
| Extensions | `app/Models/ProjectExtension.php` | `status` ∈ pending/approved/rejected; `approved_by_user_id`, `approved_at`, `rejection_reason`, `original_end_date`, `new_end_date`, `duration_days`; `isPending()`/`isApproved()`/`isRejected()` helpers; **NO `created_by_user_id` — submitter identity is currently not stored** |
| Extension flow | `app/Http/Controllers/Admin/ProjectExtensionController.php` | `store()` — admin auto-approves, staff creates pending; `update()` — admin approves/rejects (approval mutates project planned_end_date/extension_count), staff edits own pending/rejected; ActivityLog with role-aware actions |
| Issues | `app/Http/Controllers/IssueController.php` | `store()` — admin+staff only; creates ActivityLog; `Issue` model has `severity`, `issue_name`, `status` |
| Settings | `app/Models/Setting.php` | `Setting::getValue(key, default)` / `setValue(key, value)` — `key`/`value` table; used for `last_auto_send_at` |
| Scheduler | `routes/console.php` | `Schedule::command('reports:send-ai-monthly')->dailyAt('08:00')->withoutOverlapping();` — the established daily-scan pattern to mirror |
| Command precedent | `app/Console/Commands/SendMonthlyAiReports.php` | signature with `--force` option, loops projects with status filter, `Setting::getValue` toggle, console output, `Setting::setValue('last_auto_send_at', ...)` |
| Dev date sim | `app/Http/Controllers/Admin/DevDateController.php` | `session('simulated_date')` + `Carbon::setTestNow()`; routes under `dev.` prefix (web.php:671) — used to prove date-based logic in tests/browser |
| Test baseline | full suite | main @ f2598aa baseline: verify `php artisan test` count before starting (Phase D recorded 260 passed / 925 assertions) |
| Test helpers | `tests/Helpers/CreatesProjectWithHistory.php` | existing factory-based project-with-history helper for feature tests |
| Topbars | `resources/views/layouts/app.blade.php:296`, `stafflayouts.blade.php:265`, `contractorlayouts.blade.php:262` | all three have `<div class="topbar"><div class="profile-section">...` — the bell + dropdown slots here (before `.profile-info`) |
| Route groups | `routes/web.php` | staff group line 59, contractor group line 244, admin group line 325 — all `->middleware(['auth','active','role:...'])` with `prefix('staff'|'contractor'|'admin')` |
| View stack | Bootstrap 5.3 + Bootstrap Icons + SweetAlert2 loaded on every layout | no new front-end dependency for the bell/dropdown |

### Verified unknowns discovered during study

1. **Submitter identity for extensions is NOT stored.** `project_extensions` has
   `approved_by_user_id` (the *approver*, null until approved) but no column for the person
   who *submitted* the request. To notify the requester on approval/rejection, add a nullable
   `created_by_user_id` to `project_extensions` and populate it in `store()`.
2. **Notifications count in the topbar** must be computed per-request. The three layouts are
   plain `@yield`-based Blade (no layout data sharing). The bell partial will call
   `auth()->user()->unreadNotifications()->count()` and `->take(5)->get()` directly inside the
   partial include (consistent with how the layouts already call `auth()->user()` inline).
3. **Deadline reminder audience** — admins + all staff are notified; contractors are notified
   only about their OWN project's deadlines (via `project->contractor->user`).
4. **Inspection alert audience** — admins + all staff (action required); the project's
   contractor also gets a reminder for their own project.
5. **`planned_end_date`** already reflects approved extensions (updated in
   `ProjectExtensionController::update()` on approval), so deadline reminders read the live
   date with no extra join.

---

## 3. CONFIRMED DECISIONS

1. **Storage** — Laravel 12 native database notifications. Migration generated via
   `php artisan notifications:table` (default polymorphic `notifications` table). All
   notification classes use `DatabaseChannel` only.
2. **Notification classes** — one class per type under `app/Notifications/`:
   `DeadlineReminder`, `OverdueInspectionReminder`, `ExtensionStatusChanged`,
   `IssueReported`. Each defines `toDatabase()` returning `project_id`, `type` bucket/status,
   and any contextual fields (days remaining, month label, rejection reason, severity, issue
   name). Each carries a canonical tracking key for dedupe.
3. **Deduping** — a `NotificationService::notifyUnique()` helper: builds the tracking key
   (stored as `data.key`), checks
   `Notification::where('type', ...)->where('notifiable_id', $user->id)->where('data->key', $key)->exists()`,
   skips if present, otherwise creates. Escalation buckets `30/14/7/overdue` are part of the
   key so each bucket fires exactly once. Event-driven notifications (extension/issue) key on
   `extension_id`/`issue_id` so they fire exactly once ever.
4. **Audiences** — admins + all staff for action alerts; project contractor (`project->contractor->user`)
   additionally for deadline/inspection/extension on their own project; extension status changes
   target the submitter (`created_by_user_id`) + admins (+ contractor on approve/reject); issues
   target admins + the project contractor (ALL severities — user confirmed).
5. **Settings** (configurable defaults via `Setting`): `notification_enabled` (default `true`),
   `notification_deadline_days` (default `30,14,7`), `notification_overdue_grace_days` (default `7`).
6. **Scheduler** — `notifications:scan` command registered in `routes/console.php`
   `->dailyAt('07:00')->withoutOverlapping()` (mirrors the existing AI report schedule). Manual
   trigger via `php artisan notifications:scan` and (for testing) a `--force`-style option is NOT
   needed — the command is safe to run repeatedly because of dedupe.
7. **UI** — shared bell partial `resources/views/notifications/partials/bell.blade.php`
   (bootstrap dropdown: bell icon + unread badge, "Mark all read" link, latest 5 rows linking to
   the full page), included in the `.profile-section` of all three layouts. Full page
   `resources/views/notifications/index.blade.php` rendered per role via three thin wrappers
   (`index_admin/staff/contractor`) extending the correct layout, sharing one `_list` partial.
8. **Routes** — `NotificationController` registered inside all three role groups:
   `GET /notifications`, `GET /notifications/recent` (partial JSON for the dropdown), `POST /notifications/{id}/read`,
   `POST /notifications/read-all`.
9. **Extensions submitter column** — add nullable `created_by_user_id` to `project_extensions`
   (migration) + fill in `store()`; used to target approval/rejection notices.
10. **No queue needed** — notifications are written synchronously; `jobs` table untouched
    (still available for a future Phase if email delivery is requested).

---

## 4. IMPLEMENTATION STEPS (ordered; test each before the next — Rule 8)

Phases N1–N4 each land on the same `core/notification-system` branch (sequential commits;
tests per phase; no merge to main without approval).

### Phase N1 — Foundation (schema + classes + service)

1. `php artisan notifications:table` → migration `create_notifications_table` (Laravel default
   shape: `id`, `type`, `notifiable_type`, `notifiable_id`, `data` (text/json), `read_at`,
   `timestamps`, index on `(notifiable_type, notifiable_id)`).
2. NEW migration `add_created_by_user_id_to_project_extensions_table` — nullable FK to users,
   index. (Submitter identity for extension notices.)
3. Run `php artisan migrate`.
4. NEW `app/Notifications/DeadlineReminder.php` — `toDatabase()` data: `project_id`,
   `bucket` (e.g. `'30'`,`'14'`,`'7'`,`'overdue'`), `days_remaining`, `planned_end_date`,
   `project_title`; tracking key `deadline:{project_id}:{bucket}`.
5. NEW `app/Notifications/OverdueInspectionReminder.php` — data: `project_id`, `month_label`,
   `project_title`; key `overdue_inspection:{project_id}:{month_label}`.
6. NEW `app/Notifications/ExtensionStatusChanged.php` — data: `project_id`, `extension_id`,
   `extension_number`, `status` (submitted/approved/rejected), `rejection_reason` (nullable),
   `project_title`; key `extension:{project_id}:{extension_id}:{status}`.
7. NEW `app/Notifications/IssueReported.php` — data: `project_id`, `issue_id`, `issue_name`,
   `severity`, `project_title`; key `issue:{project_id}:{issue_id}`.
8. NEW `app/Services/NotificationService.php`:
   - `notifyUnique(User $user, Notification $notification, string $key)` — dedupe insert.
   - `admins(): Collection`, `allStaff(): Collection` (active users only), and
     `contractorUserFor(Project $project): ?User` (via `project->contractor->user`).
9. TEST `tests/Feature/NotificationServiceTest.php` — notifyUnique inserts once / skips on
   repeat; audiences resolve correctly (admin/staff/contractor); extension submitter linkage.

Verify: `php -l` on changed PHP; `php artisan migrate:fresh --seed` in a scratch DB (or rely on
existing `migrate` — confirm with existing workflow); `php artisan test --filter=NotificationServiceTest`.

### Phase N2 — Scheduled scan (deadline + overdue inspection)

10. NEW `app/Console/Commands/SendNotifications.php` (`notifications:scan`):
    - Read `Setting::getValue('notification_enabled', true)`; abort with message if false.
    - **Deadline reminders**: projects `whereIn('status', ['Ongoing'])` with `planned_end_date`
      set; compute days remaining from `now()`; assign bucket (`overdue` if negative; else the
      lowest configured threshold >= days remaining from `30,14,7`); for each qualifying bucket,
      notify admins + staff + project contractor (via service).
    - **Overdue inspections**: for each Ongoing project, find the earliest baseline whose
      `planned_date` < `now()->subDays(grace)` with no matching `progressHistory` record
      (`month_label`); notify admins + staff + contractor. (Mirrors
      `ProjectOverviewService::getAlerts()` inspection_pending logic but date/grace-driven.)
    - Console summary output (counts per type), mirroring `SendMonthlyAiReports`.
11. Register in `routes/console.php`:
    `Schedule::command('notifications:scan')->dailyAt('07:00')->withoutOverlapping();`
12. TEST `tests/Feature/NotificationScanTest.php` (`Carbon::setTestNow`):
    - deadline fires in `30` bucket only once, then escalates to `14`/`7`/`overdue` as the test
      clock advances (dedupe per bucket);
    - overdue inspection fires once per (project, month) and not for recorded months;
    - `notification_enabled=false` suppresses everything;
    - contractor receives own-project deadline + inspection notices.

Verify: `php -l`; `php artisan test --filter=NotificationScanTest`; manual
`php artisan notifications:scan` with a scratch project (optionally via DevDate sim in a browser
after Phase N4).

### Phase N3 — Event-driven hooks (extensions + issues)

13. MODIFY `app/Http/Controllers/Admin/ProjectExtensionController.php`:
    - `store()` — set `created_by_user_id => $user->id`; after create:
      - staff submission → `ExtensionStatusChanged(submitted)` to all admins;
      - admin submission (auto-approved) → `ExtensionStatusChanged(approved)` to the project
        contractor + all admins.
    - `update()` admin path — on transition to approved → `ExtensionStatusChanged(approved)` to
      submitter (`created_by_user_id`, if set) + project contractor + admins; on status=rejected
      → `ExtensionStatusChanged(rejected)` to submitter (with `rejection_reason`) + project
      contractor.
    - Staff resubmit path (`$wasRejected`) → `ExtensionStatusChanged(submitted)` to admins again
      (key includes status + extension_id, so a resubmit is a new event; approve/reject keys are
      distinct).
14. MODIFY `app/Http/Controllers/IssueController.php` `store()`:
    - After `Issue::create` + ActivityLog, notify admins + project contractor (ALL severities —
      user confirmed) with `IssueReported`.
15. TEST `tests/Feature/NotificationTest.php` (or extend NotificationServiceTest) — each hook:
    extension submitted → admin notified; approved → submitter + contractor + admins; rejected →
    submitter (reason present); issue created → admins + contractor; dedupe prevents re-fire.

Verify: `php -l`; `php artisan test --filter=NotificationTest`.

### Phase N4 — UI (bell + dropdown + full page)

16. NEW `app/Http/Controllers/NotificationController.php`:
    - `index()` — paginated list for the current user (read + unread), role-aware view wrapper.
    - `recent()` — latest 5 unread for the dropdown (JSON or partial view).
    - `read(Notification $notification)` — mark one read (owner check).
    - `readAll()` — mark all read for current user.
17. ROUTES in `routes/web.php` inside each role group (staff, contractor, admin):
    `GET /notifications` → `staff.notifications` / `contractor.notifications` / `admin.notifications`,
    `GET /notifications/recent`, `POST /notifications/{notification}/read`,
    `POST /notifications/read-all`.
18. NEW `resources/views/notifications/partials/bell.blade.php` — bootstrap dropdown in the
    topbar `.profile-section`: bell icon (`bi bi-bell`), unread-count badge, dropdown body with
    "Mark all read", latest 5 (title, project, relative time, unread dot), "View all" link to the
    notifications page. Included in all three layouts before `.profile-info`.
19. NEW `resources/views/notifications/index_admin.blade.php`, `index_staff.blade.php`,
    `index_contractor.blade.php` — each `@extends` the matching layout, `@include` the shared
    `_list` partial.
20. NEW `resources/views/notifications/_list.blade.php` — cards/table of notifications with
    mark-read button per row + mark-all-read; SweetAlert2 confirm on actions; empty state.
21. TEST `tests/Feature/NotificationUiTest.php` — routes accessible per role, `read`/`readAll`
    update `read_at`, owner-only read guard.

Verify: `php -l`; `php artisan view:cache` compiles; `php artisan route:list` shows the 12 new
routes (4 × 3 roles); `php artisan test --filter=NotificationUiTest`; full suite green.

### Final verification (after all phases)

- `php -l` on every changed PHP file.
- `php artisan view:cache` — all Blade templates compile.
- `php artisan route:list` — new routes registered under all three role prefixes.
- Full suite `php artisan test` — no regressions (re-baseline count vs main).
- Browser check (manual, on request): bell badge increments from a scan or a fresh issue/extension;
  dropdown lists latest; mark-read clears badge; full page paginates.

---

## 5. FILES TOUCHED

NEW:
- `textfiles/notification_system_plan.md` (this file)
- `database/migrations/*_create_notifications_table.php` (via `php artisan notifications:table`)
- `database/migrations/*_add_created_by_user_id_to_project_extensions_table.php`
- `app/Notifications/DeadlineReminder.php`
- `app/Notifications/OverdueInspectionReminder.php`
- `app/Notifications/ExtensionStatusChanged.php`
- `app/Notifications/IssueReported.php`
- `app/Services/NotificationService.php`
- `app/Console/Commands/SendNotifications.php`
- `app/Http/Controllers/NotificationController.php`
- `resources/views/notifications/partials/bell.blade.php`
- `resources/views/notifications/index_admin.blade.php`
- `resources/views/notifications/index_staff.blade.php`
- `resources/views/notifications/index_contractor.blade.php`
- `resources/views/notifications/_list.blade.php`
- `tests/Feature/NotificationServiceTest.php`
- `tests/Feature/NotificationScanTest.php`
- `tests/Feature/NotificationTest.php`
- `tests/Feature/NotificationUiTest.php`

MODIFIED:
- `app/Http/Controllers/Admin/ProjectExtensionController.php` (created_by_user_id + hooks)
- `app/Http/Controllers/IssueController.php` (issue-created hook)
- `routes/web.php` (notification routes in 3 role groups)
- `routes/console.php` (scheduler entry)
- `resources/views/layouts/app.blade.php` (bell include)
- `resources/views/layouts/stafflayouts.blade.php` (bell include)
- `resources/views/layouts/contractorlayouts.blade.php` (bell include)

NOT touched:
- `app/Models/User.php` (Notifiable already present)
- `jobs` table / queue wiring (out of scope; synchronous writes)
- Email delivery (out of scope this phase)
- `ProjectOverviewService::getAlerts()` (derived per-project card stays as-is; scan logic is new)

---

## 6. OPEN QUESTIONS / STILL TO RESOLVE

1. **Schedule time** — `07:00` daily chosen (AI report runs at 08:00). Adjustable.
2. **Overdue grace** — default `7` days past `planned_date` before an inspection alert fires.
   Adjustable via `Setting`.
3. **Bell dropdown load** — server-rendered inline in the partial (no AJAX) for simplicity;
   `recent()` endpoint exists if the user later wants live polling.
4. **Notification retention** — no pruning this phase; admins can clear via read-all (kept for
   audit). Add a `Notification::prune` command later if volume grows.
5. **Contractor issue scope** — contractor is notified for ALL new issues on their project
   (user confirmed "all"). Severity filter can be added via Setting later if desired.

---

## 7. PROCESS NOTES

- Rule 12: work only on `core/notification-system`; do NOT merge to main without explicit user approval.
- Rule 8: test after each phase (N1 → N4 in order).
- Rule 10: inform the user after the feature completes.
- Rule 11: log progress in §8 as each phase lands.
- Plan-only commit BEFORE implementation (established pattern: Phase A–D plans were committed before any code).
- This branch contains only the plan + related docs in its first commit; no implementation in the plan commit.

---

## 8. SESSION / CHANGES LOG

(Chronological log of what is built and any bugs found — append as the feature is implemented.)

### 2026-08-04 — Plan created
- Branch `core/notification-system` created from `main` @ `f2598aa`.
- Codebase study complete (User/Notifiable, roles, extension + issue flows, scheduler, settings,
  three layouts/topbars, routes groups verified).
- Plan written (this file); committed as plan-only (no implementation); pushed to GitHub.
- User confirmations captured: all four notification types; contractors included; scheduler via
  schedule:work/cron; deadline buckets `30/14/7` + overdue; issues notify all severities.

### 2026-08-04 — Phase N1 implemented (Rule 8: tested per step)
- **N1.1:** `2026_08_04_000001_create_notifications_table.php` (Laravel polymorphic shape via
  `morphs`; removed the redundant explicit index the `morphs()` helper already creates — caught by
  the sqlite test run).
- **N1.2:** `2026_08_04_000002_add_created_by_user_id_to_project_extensions_table.php` (nullable FK,
  guarded by `Schema::hasColumn`, index) + `ProjectExtension::$fillable` entry for the new column.
- **N1.4:** four notification classes under `app/Notifications/` — `DeadlineReminder`,
  `OverdueInspectionReminder`, `ExtensionStatusChanged` (with SUBMITTED/APPROVED/REJECTED consts),
  `IssueReported` — each returning a `data` payload with a canonical `key` for dedupe.
- **N1.5:** `app/Services/NotificationService.php` — `notifyUnique()` (reads `data.key`, skips
  existing same-type+key rows, ignores inactive users), `notifyMany()` (fans out with per-user
  dedupe), `admins()` / `allStaff()` (active only), `contractorUserFor(Project)`.
- **N1.6:** `tests/Feature/NotificationServiceTest.php` — 16 tests / 37 assertions (dedupe,
  escalation buckets, inactive skip, audience resolvers, all four payload shapes, submitter persist).
- **N1.3 (deferred):** `php artisan migrate` on the real MySQL DB NOT run — local MySQL was not
  running during implementation. Migrations validated via the sqlite test suite; run migrate when
  MySQL is up.
- **Verification:** `php -l` clean on all changed PHP; full suite **276 passed / 962 assertions**
  (baseline 260/925 + 16/37 new; zero regressions).

### 2026-08-04 — Phase N2 implemented (Rule 8: tested per step)
- **N2.1:** `app/Console/Commands/SendNotifications.php` (`notifications:scan`) — reads
  `notification_enabled` / `notification_deadline_days` (30,14,7) / `notification_overdue_grace_days` (7)
  Settings; resolves recipients (active admins + staff + the project's contractor); sends deadline
  reminders into the SMALLEST qualifying bucket and overdue-inspection alerts for the earliest
  unrecorded baseline month past the grace window. Fixed during testing: `resolveDeadlineBucket()`
  originally returned the LARGEST qualifying bucket (always '30') — now sorts ascending and returns
  the smallest bucket >= days remaining.
- **N2.2:** `routes/console.php` — `Schedule::command('notifications:scan')->dailyAt('07:00')->withoutOverlapping();`.
- **N2.3:** `tests/Feature/NotificationScanTest.php` — 11 tests / 23 assertions (30/14/7/overdue
  buckets, escalation once-per-bucket, grace window, recorded-month skip, once-per-(project,month),
  completed-project exclusion, disabled toggle, contractor isolation). Uses `Carbon::setTestNow`.
- **Verification:** `php -l` clean; full suite **287 passed / 985 assertions** (N1 276/962 + 11/23;
  zero regressions). `schedule:list` not runnable in this env (MySQL down — cache driver hits DB);
  registration verified via tests + console.php diff.

---

END OF NOTIFICATION SYSTEM PLAN

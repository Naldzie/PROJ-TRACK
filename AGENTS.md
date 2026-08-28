# AGENTS.md

PMU System (PROJ-TRACK): a Laravel 12 (PHP 8.2) project-monitoring app for CSPC PMU. Tracks projects, baseline schedules, progress, extensions, issues, warranties, AI predictions, ratings, and email reports. Auth is Google-only via `laravel/socialite` (no local registration).

## System Modules (passed title defense)

1. **Project Proposal Encoding and Management** (PMU-Controlled) — CRUD for projects (create, edit, archive, restore, delete). Fields: title, code, category, contractor, budget, start/end dates, scope, documentation. Proposal upload with auto-approval. Project code auto-generated (PRJ-YYYY-NNNNN).

2. **Milestone Tracking** (monthly cumulative progress — spec title not renamed) — Baseline schedules with monthly cumulative % targets. Activities are checklist items within each monthly target. S-curve chart with planned vs actual vs adjusted plan. Extension-adjusted deadlines. NOT individual weighted milestones — the original milestone design was replaced per client request.

3. **Contractor Viewing Access** (view-only) — Contractors see only their assigned projects. Read-only access to dashboard, project details, S-curves, documents, issues, risks, email reports, extensions. Write access limited to: warranty submissions and profile edit.

4. **Automated Notification and Reminder** — 6 notification types: deadline reminders (30/14/7 days + overdue), overdue inspection alerts, extension status changes, issue reported, risk reported, risk level changed. Scheduled via `notifications:scan` (daily 07:00) + event-driven from controllers. Deduplication via tracking keys. Bell widget + full inbox UI.

5. **Document Archiving and Retrieval** — Upload, categorize (configurable document types), inline view, Excel-to-HTML preview, download, delete with physical file cleanup. Contractors: view/download only.

6. **Dashboard and Reporting** — 3 role-specific dashboards (admin, staff, contractor) with real data, stat cards, health badges, activity feed, filters. 6 export types: progress Excel, baseline Excel, extensions Excel, issues Excel, summary PDF (with embedded S-curve), PDF preview.

7. **Regression-Based Predictive Analytics** — SLR + polynomial regression, auto model selection, reference-class blending (cross-project similarity), multi-method completion estimation, prediction bands, forecast reliability scoring (0-100), class standing, prediction history with backtesting, contractor performance integration, recommendation engine (rule-based with AI fallback), AI text explanations.

## Client Workflow Notes

- Client does **NOT** record prospective risks — only known issues and problems.
- The Risk Register (Issues & Risks tab) exists as an **optional feature** for users to leverage if they choose. It may be removed or reworked later based on client feedback. Do NOT build new features on top of the risk register until the client confirms they want to keep it.
- The PMU's workflow is: Project Created → Baseline Schedule → Contractor Work → Monthly Inspection → Record Progress → Compare Against Baseline → Management Decision → Completion or Extension.

## Commands

- Dev stack: `composer dev` (runs `php artisan serve` + `queue:listen` + vite + `schedule:work` concurrently). A queue worker **must** be running (`QUEUE_CONNECTION=database`) for AI report emails / notifications to process. The scheduler runs in a CLI context (no session), so it uses real time only — the browser Date Simulator drives deadline/overdue scans solely through the dev widget "Run Notification Scan" button.
- Tests: `composer test` (clears config, then `php artisan test`). Single class: `php artisan test --filter=NotificationTest`. Tests use SQLite `:memory:` (see `phpunit.xml`); the live app uses MySQL `pmu_system4`.
- Seed categories after a fresh DB: `php artisan db:seed --class=CategorySeeder` (seeds exactly 4: New Construction, Renovation, Repair, Maintenance).
- Scheduler lives in `routes/console.php`, NOT `app/Console/Kernel.php` (its `schedule()` is empty): `reports:send-ai-monthly` at 08:00, `notifications:scan` at 07:00.

## Frontend: no-vite convention

App UI CSS/JS lives in `public/css` and `public/js`, linked via `asset('...')` in Blade layouts (`stafflayouts`, `contractorlayouts`, `app`). Do NOT add new feature styles/scripts to `resources/` or `@vite` them — follow the existing `public/` convention (e.g. `public/css/admin/projects/`, `public/js/admin/projects/`). The only `@vite` usage is the legacy `app.blade.php` bootstrap.

## Architecture

- Three role route groups in `routes/web.php`, prefixed `/admin`, `/staff`, `/contractor` with middleware aliases `role` + `active` (defined in `bootstrap/app.php`).
- `ProjectController` is shared by all three roles; per-role authorization is enforced inside the controller, not via separate controllers. Other controllers are split per role under `app/Http/Controllers/{Admin,staff,contractor}`.
- Business logic lives in `app/Services/` (e.g. `AI/` for regression/prediction services, `NotificationService.php`, `AIReportSendingService.php`). Models in `app/Models/`.
- App-wide date simulation: `SimulateDate` middleware + `/dev/*` routes (non-production only) set `Carbon::setTestNow()` via session. Use this to test deadline-sensitive features.

## Known Issues

- `RiskLevelChanged` notification: bell dropdown shows "Issue reported" instead of correct label (fix documented in `textfiles/bugs_and_behavior_adjustments.md`).
- AI Prediction tab shows "ON TRACK" while dashboard/index shows "At Risk" for same project — escalation factors not applied in AI presenter (fix documented in `textfiles/bugs_and_behavior_adjustments.md`).
- Risk level notification only triggers on manual progress recording, not on month boundaries (fix documented in `textfiles/bugs_and_behavior_adjustments.md`).
- `GET /admin/projects/{id}/alternative` route exists in `routes/web.php` but controller method is missing (would 500). Safe to remove during dead code cleanup.
- `MilestoneController` + `ProjectMilestone` model are legacy dead code — the original milestone system replaced by baseline schedules. Not rendered in UI. Can be removed.
- `EmailDraftTest` — 2 pre-existing test failures (mailable not sent in test context).

## Tests

- Feature tests use `RefreshDatabase` and factories; model factories must stay valid (see `tests/Feature/Infrastructure/FactoryVerificationTest.php`).
- `Tests\Helpers\CreatesProjectWithHistory` trait builds regression datasets for AI tests, including the known "Project 7" dataset (slope ≈ 5.115, R² ≈ 0.954) — don't change those expected values.
- One test file per feature area (e.g. `NotificationTest.php`, `ProgressAttachmentTest.php`).
- Full suite: 983 tests / 3468 assertions (2 pre-existing `EmailDraftTest` failures excluded).

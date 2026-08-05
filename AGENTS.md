# AGENTS.md

PMU System (PROJ-TRACK): a Laravel 12 (PHP 8.2) project-monitoring app for CSPC PMU. Tracks projects, baseline schedules, progress, extensions, issues, warranties, AI predictions, ratings, and email reports. Auth is Google-only via `laravel/socialite` (no local registration).

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

## Tests

- Feature tests use `RefreshDatabase` and factories; model factories must stay valid (see `tests/Feature/Infrastructure/FactoryVerificationTest.php`).
- `Tests\Helpers\CreatesProjectWithHistory` trait builds regression datasets for AI tests, including the known "Project 7" dataset (slope ≈ 5.115, R² ≈ 0.954) — don't change those expected values.
- One test file per feature area (e.g. `NotificationTest.php`, `ProgressAttachmentTest.php`).

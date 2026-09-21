# Google Calendar Auto-Sync + Dashboard Calendar Plan

## Request (2026-09-21)
Project start and due/end dates should be automatically put in the Google
Calendars of admin, staff and contractor — and also in the dashboard calendar.
Scope chosen: full auto-sync now, recipients = everyone involved (all active
admins + assigned PM + contractor user), dates = start + current deadline +
monthly baseline inspection dates.

## What was built

### 1. OAuth / consent (login keeps working without it)
- `AuthController::redirectToGoogle` now requests
  `openid email profile + https://www.googleapis.com/auth/calendar.events`
  with `access_type=offline` + `prompt=consent select_account`, so first
  login after this change stores a refresh token for background syncs.
- `AuthController::handleGoogleCallback` stores tokens via the shared
  `GoogleCalendarController::storeTokens()` helper, which never wipes a
  stored refresh token when Google omits it (only sent on first consent).
- New `GoogleCalendarController`: `connect`/`callback` (incremental consent
  for already-logged-in users, no re-login), `status`, `disconnect`,
  `sync` (manual per-project re-sync, admin/assigned-staff), `ics`
  (no-OAuth `.ics` download for all roles with project access).
- `config/services.php`: `google.calendar_scopes` added.
- Migration `2026_09_21_000001`: `users.google_id`, `google_refresh_token`,
  `google_token_expires_at`, `google_calendar_enabled`,
  `google_calendar_connected_at`. (`google_token` holds the access token;
  `google_email` already existed. Note: `AuthController` previously wrote
  `google_id` which had no column and was silently dropped — now persisted.)
- `User`: fillable/casts extended + `hasGoogleCalendar()`.

### 2. Sync engine (never breaks project saves)
- `GoogleCalendarService`: recipient resolution, desired events
  (`start` from `planned_start_date`, `due` from `planned_end_date`,
  `inspection:{scheduleId}` per baseline month), all-day payloads with
  reminders (due: 7d+1d, inspection: 1d), per-event try/catch with
  warn-and-count semantics, stale-event deletion, plus no-OAuth fallbacks
  (`googleTemplateUrl()`, `icsForProject()`, `templateLinksFor()`).
- `ProjectCalendarEvent` model + migration `2026_09_21_000002`: one row per
  (project, user, event key) holding Google's event id → re-syncs UPDATE in
  place (extension-moved deadlines), removed baseline months are deleted.
- `SyncProjectCalendar` job (ShouldQueue, 3 tries with backoff).
- `ProjectObserver` (created + updated on synced fields incl.
  `planned_end_date`/`contractor_id`/`assigned_project_manager`) and
  `BaselineScheduleObserver` (saved/deleted) dispatch the job `afterCommit`.
  Extension approvals flow through `Project::update()` in
  `recomputeProjectDeadline()`, so they are covered with no controller edits.
- Google Cloud prerequisites (not code): enable the Calendar API on the same
  project as the OAuth client, add the `calendar.events` scope + test users
  on the consent screen. Without this, pushes skip cleanly and the UI
  fallbacks (Add-to-Google links, `.ics`) keep working.

### 3. Dashboard calendar (real data, all roles)
- New `DashboardCalendarService::forProjects()` — one flat event per dot
  (start ■ / deadline ● / inspection ring) with health-bucket status,
  monthly planned-vs-actual context, and role-aware detail URLs.
- `DashboardController@index` (serves admin + staff, staff-scoped) and
  contractor `DashboardController@index` now pass `$calendarProjects`.
- `calendar-widget.blade.php`: hardcoded 9-project demo array replaced with
  `@json($calendarProjects)`; legend + dot shapes per kind; modal shows
  event badge, start→due timeline, inspection month, role-aware View button,
  "Add to Google" template link + `.ics` fallback; date matching is
  timezone-safe (day keys, no `new Date('YYYY-MM-DD')` drift).
- Contractor dashboard gained a full Project Calendar panel (+ connect
  banner); admin/staff dashboard shows a connect banner until linked.
- Project header gained a Calendar menu: manual sync (admin/staff), `.ics`
  download (all), connect link (when unconnected).

## Verification (2026-09-21)
- Migrations applied to live MySQL (`pmu_system4`) OK.
- New `tests/Feature/ProjectCalendarSyncTest.php`: 6 passed / 28 assertions
  (feed shape, recipients, deadline-move tracking, template URL, .ics,
  no-consent skip with zero failures).
- Regression: `ContractorDashboardTest|DashboardHealthTest|ProjectsRenderTest`
  — 32 passed (covers admin + contractor dashboard renders and project
  detail header). Pre-existing failures per AGENTS.md not re-run here.

## REVERT (2026-09-21) — calendar scope removed from login
Requesting `calendar.events` at login forced Google app verification and
produced `Error 403: access_denied` for anyone not on the Cloud console
tester list while the app is in testing mode. Reverted
`AuthController::redirectToGoogle` to the original basic-profile login
(`prompt=select_account`, no extra scopes, no offline access) so sign-in
works for everyone again.
Kept: token storage via `storeTokens()` (now also persists `google_id`,
whose column previously didn't exist), the optional per-user connect flow,
observers/job/service, dashboard real-date calendar, `.ics` + template
fallbacks. Auto-push works only for users who complete the connect flow
(approved testers until verification passes); everyone else is skipped
cleanly and keeps the dashboard calendar + `.ics` links.
To re-enable one-click consent at login after verification passes, restore
the scopes + `access_type=offline` + `prompt=consent` block in
`redirectToGoogle` (see git history for this file).

## DIAGNOSIS (2026-09-21) — "connected but nothing in Google Calendar"
Live push test (`C:\temp\opencode\cal_push_test.php`) proved the app side is
fine (token valid, recipients resolve, 2 events desired for project #1) but
Google returns 403 `accessNotConfigured`: the Calendar API is DISABLED on
Cloud project 1073829316144. Fix: enable it at
https://console.developers.google.com/apis/api/calendar-json.googleapis.com/overview?project=1073829316144
then re-sync ("Sync to Google Calendar now" or any project edit). Failed
attempts are recorded as `failed` rows and retried on next sync.
Also created the missing `failed_jobs` table (queue failures were invisible
before). Note: with no queue worker running, observer-dispatched backfills
never run — manual "Sync now" runs inline and works regardless.

Second error after enabling the API: 403 `insufficientPermissions` /
`ACCESS_TOKEN_SCOPE_INSUFFICIENT` — the stored grant has no calendar scope.
Cause: the scope was never added on the consent screen's Data access tab
(or the user consented before it was added). Fix in console: OAuth consent
screen → Data access → add `.../auth/calendar.events` → save (test users
must include the account), then the user must reconnect: project header →
Calendar → Disconnect, revoke at myaccount.google.com/permissions, then
Connect again. Added the Disconnect menu item for exactly this cycle.

## DASHBOARD CALENDAR FIXES (2026-09-21)
- Hovering any dated cell now previews that day's events (dark tooltip from
  `data-daytip`, up to 6 + overflow count); clicking the cell opens the
  first event, each dot stays individually clickable with `stopPropagation`.
- Dots enlarged 7px → 9px (11px in full-month view) with padded rows —
  the old dots were too fiddly to hit.
- Cache-busted `dashboard-widgets.css` (`?v=20260921c`) in both layouts +
  contractor view so the kind shapes/hover styles actually load.
- Widget JS + header button script both pass `node --check`; dashboard
  render tests green.

## Follow-ups (need explicit order)
- Browser-prove: connect flow, auto-push on create/extension, dashboard dots.
- Optional: sync activity-log entries / notification when pushes fail.
- Optional: delete pushed events when a project is archived.

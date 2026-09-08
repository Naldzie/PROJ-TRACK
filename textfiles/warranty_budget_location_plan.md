# Warranty — Project Budget & Location Display

## 2026-09-07 — Add budget + location to all warranty views
- Request: show project budget (from existing data) and location (all in "Camarines Sur Polytechnic Colleges") in every `warranty.blade`.
- Source of truth: `projects.approved_budget` (decimal) and `projects.location` (string). Confirmed via `Project.php` fillable/casts, `ProjectSeeder.php` sample data (e.g. ₱4,500,000.00 / "Barangay San Isidro, Main Street", ₱3,200,000.00 / "CSPC Main Campus, Engineering Building"), and existing `₱ number_format` convention in project views.
- Behavior: location displays `$project->location` when set, falls back to `Camarines Sur Polytechnic Colleges` when null/empty (so existing seeded locations are preserved, missing ones show CSPC per request).
- Budget format: `₱ 1,234,567.89` (`number_format((float) approved_budget, 2)`), `N/A` when null.

### Files changed
- `resources/views/admin/warranty.blade.php` — list item `data-budget`/`data-location`, `viewWarranty()` +7th/8th args (budget, location), detail-grid `dcBudget`/`dcLocation` cards, `filterWarranties()` searches new fields, `allWarranties` JSON gains `budget`/`location`.
- `resources/views/staff/warranty.blade.php` — same as admin (staff routes preserved).
- `resources/views/contractor/warranty.blade.php` — same (contractor `viewWarranty()` has no `projectId` arg; signature extended without it).
- `resources/views/warranty/preview.blade.php` — `$rows` map gains `budget`/`location`; table gains "Project Budget" + "Location" columns; empty-state `colspan` 8 → 10.

### Verification
- `php artisan view:clear && php artisan view:cache` — Blade templates cached successfully.
- No warranty feature tests exist; no model/controller logic touched (display-only, relations already eager-loaded via `with(['project', 'contractor'])`).

## 2026-09-07 — Public warranty preview + login button
- Request: preview button on `login.blade` opening the warranty preview publicly (no sign-in).
- Previous state: `admin.warranty.preview` (`/admin/warranty/preview`) and `staff.warranty.preview` (`/staff/warranty/preview`) both sit behind `auth` (+`active`+`role`) middleware — unreachable from the login page.
- Change (additive, existing auth routes untouched):
  - `Admin\WarrantyController::publicPreview()` — same read-only `warranty.preview` table (no file links/downloads, no write actions), no auth data used.
  - `routes/web.php` (Public Routes section): `GET /warranty/preview` → `warranty.preview.public`. Note: URI does not collide with the prefixed `/admin|staff/warranty/preview` routes.
  - `resources/views/auth/login.blade.php` — dashed secondary `.preview-btn` ("Preview Warranty Records" + external-link icon, `target="_blank" rel="noopener"`) under the Google button, matching card styling + entrance animation. (`admin/login.blade.php` left alone — it is the activity-log view, misnamed, not a login page.)
- Data-exposure note: the public table lists project titles, contractor names, budgets, locations, dates, remarks. Approved as requested; file attachments remain behind auth (preview has no file URLs).
- Verification: `route:clear` was needed (stale route cache hid the new route); `route:list` confirms all 3 preview routes. Live smoke test via `artisan serve`: `/login` 200 with button present, `/warranty/preview` 200 with overview + Project Budget column, no session.

## 2026-09-07 — Centered institutional preview header
- Request: preview header reads "Camarines Sur Polytechnic Colleges Projects Ongoing Records", centered.
- Change (`warranty/preview.blade.php` only): `h1` + `<title>` renamed; `.preview-title` switched from `space-between` row to centered column layout (`title-left` centered, page dots fall below the title, still centered). Chose center over justify (headers center; body/table text stays left-aligned for readability).
- Verification: `view:cache` clean; live smoke test `/warranty/preview` 200 with new title present.

## 2026-09-07 — Hands-free auto-scroll on public preview
- Request: preview auto-scrolls so records can be read while shown automatically (display-board use).
- Change (`warranty/preview.blade.php` only, existing 30s page rotation kept):
  - New auto-scroll IIFE: slow read-downward scroll (~55px/s) → 4.5s rest at bottom → fast glide back to top → 2.5s rest → repeat. Starts after 2.5s.
  - Page-rotation hook: `showPage()` calls `window.__warrantyAutoScrollReset()` so each rotated page restarts at the top.
  - Pauses on viewer interaction (wheel/touch/mousedown/scroll keys), resumes after 10s idle; `dt` clamped so tab-switches don't jump.
  - Bottom-right `#autoscroll-toggle` pill (❚❚ on / ▶ off) to pause/resume; hidden in print. Honors `prefers-reduced-motion` (starts off, toggle explicitly opts in).
  - No scroll when content fits the viewport (`maxScroll() <= 2`).
- Verification: `view:cache` clean; live `/warranty/preview` 200 with toggle + hook present; first inline `<script>` extracted from rendered HTML passes `node --check`. (Note: greedy-regex extraction initially caught Debugbar-injected scripts — dev-only artifact, not a code issue.) Stray `artisan serve` test processes stopped.

## 2026-09-07 — Sideways auto-pan (full presentation mode)
- Request: auto side-scroll too, so the preview runs like a presentation for showing.
- Change (`warranty/preview.blade.php` script only): display loop is now a 4-phase sequence — read **down** the rows (55px/s) → rest → pan **right** across the columns (~130px/s inside `.table-responsive`) → rest 3s → pan **left** back → rest → glide **up** to top → repeat. Axes with no overflow are skipped per frame; page-rotation reset now returns to top-left; left/right arrows also pause the loop. Same toggle, same interaction resume, same reduced-motion/print behavior.
- Verification: `view:cache` clean; live `/warranty/preview` 200 with side-pan code present; rendered script passes `node --check`. Test serve processes stopped.

## 2026-09-07 — Present button: fullscreen display + auto-scroll in one press
- Request: the auto-scroll button should double as a full-view/full-display button.
- Change (`warranty/preview.blade.php` only): the pill is now a one-press presentation control (`#present-toggle`, old `autoscroll-toggle` id fully renamed in CSS/HTML/JS).
  - Click ⛶ **Present** → enters browser fullscreen AND starts the scroll/pan show. Fullscreen denial is caught so the scroll-show still runs windowed.
  - Click again (❚❚ **Presenting…** / **Auto-scroll on**) → exits fullscreen AND stops the show.
  - Esc / system fullscreen exit also ends the presentation via `fullscreenchange`/`webkitfullscreenchange` sync. Safari prefix fallbacks included.
  - Button label is truthful per state: `Present` (off) / `Auto-scroll on` (scrolling windowed, e.g. on page load) / `Presenting…` (fullscreen + scrolling). Hidden in print as before.
- Verification: `view:cache` clean; live `/warranty/preview` 200 with new button + `requestFullscreen` present and no stale id; rendered script passes `node --check`. Test serve processes stopped.

## 2026-09-07 — Slower presentation scroll
- Request: slow down the auto-scrolling.
- Change (`warranty/preview.blade.php` constants only): `DOWN_SPEED` 55 → 30 px/s, `SIDE_SPEED` 130 → 70 px/s. Return glide `UP_SPEED` kept at 500 (not reading speed). Rule of thumb for future tuning: lower number = slower.
- Verification: `view:cache` clean; live render contains new speeds; rendered script passes `node --check`. Test serve processes stopped.

## 2026-09-07 — 5-minute wait before first scroll
- Request: 5 minutes before it starts scrolling.
- Change (`warranty/preview.blade.php`): new `FIRST_PAUSE = 300000` used for the initial `pauseUntil`; repeat loops still use the short `TOP_PAUSE` (2.5s). Page-rotation resets and the Present button intentionally stay short (rotation ticks every 30s; pressing Present means "start now").
- Verification: `view:cache` clean; live render contains `FIRST_PAUSE = 300000`; rendered script passes `node --check`. Test serve processes stopped.

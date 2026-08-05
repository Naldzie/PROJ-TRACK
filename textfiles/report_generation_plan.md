# REPORT GENERATION — PDF / Excel Exports (Gap #10)

CSPC PMU System — textfiles/gaps_and_features_missing.txt item #10

Status: 🟢 IN PROGRESS — R1 (Excel exports) and R2 (PDF summary) implemented
        and committed on `core/report-generation`; **three PDF quality fixes**
        (peso sign, S-Curve flat-0% bug, latest-inspection selection) plus a
        **view-before-download PDF viewer flow** shipped in follow-up commits
        (`3e58fe0`, `2ecc0e9`, `52e2f4e`, `1bb101a`). **Excel exports are
        frozen** until the unexplained corruption report is resolved (see
        §6.4). Remaining: client confirmation, browser check, merge to main
        (Rule 12 — requires explicit approval).

Date: August 5, 2026 (updated after PDF-fix + viewer round)

Rules: textfiles/active_reference/project_context_and_development_rules.txt (Rule 8/10/11/12)

---

## 1. OBJECTIVE

Add report-generation so the PMU can **export** the project-monitoring data it
already tracks. Today the system can display progress, S-Curve, issues, and
extensions in the browser but can **export nothing** (verified: no
`StreamedResponse`, no export routes, no export libs in `composer.json`).

Deliverables (per gap #10 scope):

- **Excel: Progress History** — every `ProjectProgressHistory` row (month,
  inspection date, planned %, actual %, variance, remarks, recorded by,
  evidence count).
- **Excel: Baseline Schedule** — every `ProjectBaselineSchedule` row (month
  label, planned date, planned progress %, description).
- **Excel: Extensions** — every `ProjectExtension` row (extension #, original
  end date, new end date, duration days, status, reason, approved at/by).
- **Excel: Issues** — every `Issue` row (name, type, severity, status, linked
  month, created, resolved, resolution notes).
- **PDF: Project Summary** — a stakeholder-ready document: header (code, title,
  category, contractor, location, budget, dates, status), key stats (progress,
  days remaining, risk), the **S-Curve chart** (planned vs actual vs extension),
  a compact progress table, open-issue list, and extension history.

Design pillar: a single shared `ReportExportService` (mirrors the shared
`ProjectController` used by all three roles). Excel via `phpoffice/phpspreadsheet`
(native `.xlsx`, no facade), PDF via `barryvdh/laravel-dompdf` (Blade → PDF).
Both are pure-PHP (no node build, no browser) and match the no-vite convention.

**Environment check (verified 2026-08-05):** Composer 2.8.5, PHP 8.2.12,
extensions `zip` + `xmlwriter` present (required by PhpSpreadsheet),
**GD was initially NOT installed** (so charts in the PDF must be rendered as
**inline SVG**, not PNG — dompdf ships a built-in SVG image renderer usable via
`data:image/svg+xml;base64,...`). GD was later **enabled** (un-commented
`extension=gd` in `C:\xampp\php\php.ini`) because PhpSpreadsheet 4.x requires it.
Packagist reachable (HTTP 200) so `composer require` will work.

> **Deviation (deps):** `barryvdh/laravel-dompdf` is **NOT Laravel 12-compatible**
> (requires illuminate ^9|^10|^11). We installed **`dompdf/dompdf:^3.0`** directly
> instead and drive it with the bare `Dompdf` class in `ReportExportService`
> (no facade/wrapper). Same result (Blade HTML → PDF), one fewer dependency.

---

## 2. CURRENT STATE & PATTERNS TO MIRROR (verified 2026-08-05)

| Concern | Reference | Pattern |
|---|---|---|
| Shared project controller | `app/Http/Controllers/ProjectController.php::show()` | One controller served to all three roles; per-role authorization enforced inside (`in_array($user->role, ['admin','staff'])`, contractor scoped to own `project_id`). Mirror this for the report controller. |
| Project model | `app/Models/Project.php` | `progressHistory()` (ordered by inspection_date), `baselineSchedules()`, `extensions()` (ordered by extension_number), `issues()`, `contractor()` (company_name), `category` (name), status, dates, `approved_budget`, `getProgressPercentage()`, `getDaysRemaining()`, `isOverdue()`, `isAtRisk()`. |
| Progress model | `app/Models/ProjectProgressHistory.php` | `month_label`, `inspection_date`, `planned_progress_percent`, `actual_progress_percent`, `progress_percentage`, `variance_percent`, `remarks`, `recordedBy` (name), `attachments()` morphMany (count = evidence). |
| Baseline model | `app/Models/ProjectBaselineSchedule.php` | `month_label`, `planned_date`, `planned_progress_percent`, `description`. |
| Extension model | `app/Models/ProjectExtension.php` | `extension_number`, `original_end_date`, `new_end_date`, `duration_days`, `reason`, `status` (pending/approved/rejected), `rejection_reason`, `approver` (user), `approved_at`. |
| Issue model | `app/Models/Issue.php` | `issue_name`, `issue_type`, `severity`, `status`, `baselineSchedule` (month), `resolved_at`, `resolution_notes`, `created_at`. |
| Chart data source | `app/Services/ProjectChartService.php::prepareChartData()` | Returns `labels`, `frozen_planned_data`, `adjusted_planned_data`, `actual_data`, `has_extension`, `y_max` — exactly what the PDF S-Curve SVG needs. Access via `app(ProjectDetailService::class)->prepareChartData($project)`. |
| S-Curve UI render | `resources/views/projects/project_detail/components/tabs/progress.blade.php` | The on-screen chart is Raphael/justgage (client SVG). The PDF needs a **server-generated** SVG polyline instead. |
| Detail header | `components/header.blade.php` `.header-badges-right` | Existing action-button slot (Proposal/Edit) — add the "Reports" dropdown here. |
| Download precedent | `AttachmentController.php:201`, `DocumentController.php:265` | `Storage::disk('public')->download(...)` returns a binary file response. Exports use `response()->streamDownload(...)` instead (generated on the fly, no storage). |
| File streaming | (none yet) | `streamDownload()` callback writes the workbook/PDF to `php://output`. Laravel's test helper `streamedContent()` reads it back for assertions. |
| Routes | `routes/web.php` | Three role groups with prefixes `staff`/`contractor`/`admin` and `->middleware(['auth','active','role:...'])`. Add export routes inside each group. |
| Tests | `tests/Feature/*` | `RefreshDatabase`, controllers invoked directly, users via `User::create()` (no factory), factories exist for all 5 models. |
| Factories | `database/factories/` | `ProjectFactory`, `ProjectBaselineScheduleFactory`, `ProjectProgressHistoryFactory`, `ProjectExtensionFactory`, `IssueFactory`. |

### Verified unknowns discovered during study

1. **No export library installed.** `composer.json` has no `phpspreadsheet` /
   `dompdf` / `maatwebsite`. Must `composer require` two packages (R1 step).
2. **GD is not installed** (confirmed via `php -m`). PNG chart rasterization is
   unavailable → the PDF chart must be **SVG**. dompdf ≥ 2.0 can render simple
   SVG images (polylines/rectangles/text) embedded as base64 data URIs. A
   plain-HTML fallback (bars built with CSS + tables) is the documented Plan B.
3. **Excel streaming in tests** — `StreamedResponse::streamedContent()` returns
   the bytes after the callback runs; tests can load them back with
   `PhpOffice\PhpSpreadsheet\IOFactory::load` from a temp file to assert cell values.
4. **Contractor scope** — contractors are read-only viewers (design decision).
   Export routes should follow `ProjectController::show()` authorization:
   admin/staff export any project; contractor exports only their own project.

---

## 3. CONFIRMED DECISIONS

1. **Library — Excel:** `phpoffice/phpspreadsheet` (^4.0). Native
   `.xlsx` via `Xlsx` writer; streams to `php://output`. No facade wrapper
   (keeps the service layer plain; mirrors the project's bare-service style).
2. **Library — PDF:** `dompdf/dompdf` (^3.0) directly (NOT the barryvdh wrapper —
   see deviation note above; barryvdh requires illuminate ^9|^10|^11).
   Renders a dedicated Blade template to PDF; streams the result.
3. **One shared controller + one shared service** registered in all three role
   groups — mirrors `ProjectController`. Per-role authorization enforced inside.
4. **PDF S-Curve = inline SVG** built server-side from
   `ProjectChartService::prepareChartData()` (labels + frozen/adjusted planned +
   actual + extension). Embedded as a base64 `data:image/svg+xml` `<img>`
   (dompdf has no inline `<svg>` frame handler — only `<img>` routes through
   php-svg-lib). **Point shape confirmed:** chart series are associative arrays
   `['x' => ms-timestamp, 'y' => value]`, NOT scalars — so the builder maps each
   `x` onto the label grid via `tick_timestamps` and reads `y` from the array,
   then emits a legend (Frozen Baseline / Adjusted Planned / Actual) and
   month-name x labels (`April 2026`). Fallback if php-svg-lib misbehaves: GD
   PNG rendering (GD is now enabled) or an HTML/CSS bar table.
5. **Routes** — one route per report type, named per role:
   `{role}.projects.exports.progress` etc. Controller methods
   `progressExcel / baselineExcel / extensionsExcel / issuesExcel / summaryPdf`.
   Plus `{role}.projects.exports.summary.view` (`summaryPdfView`) serving the
   same PDF inline (`Content-Disposition: inline`, no audit-log entry) for the
   viewer-modal iframe.
6. **Auth** — admin/staff any project; contractor own project only (reuses the
   exact `show()` authorization block).
7. **UI** — a "Reports" dropdown (Export Excel → 4 items, View PDF Summary) in
   the project header `.header-badges-right`, plus a small inline
   "Export Excel" button in the Progress tab card header (progress.blade.php
   card header already uses flexbox `justify-content: space-between`). The PDF
   item now opens the **existing document-viewer modal**
   (`openDocumentViewer({viewUrl, downloadUrl})`); Excel items remain direct
   download links until the corruption issue is resolved.
8. **File naming** — `{project_code}_progress.xlsx`,
   `{project_code}_baseline.xlsx`, `{project_code}_extensions.xlsx`,
   `{project_code}_issues.xlsx`, `{project_code}_project-summary.pdf`.
9. **Activity log** — record an `ActivityLog::create` (action `Export Report`,
   project_id set) on each export, consistent with the audit-trail pattern.
   **Previews (inline `summaryPdfView`) do NOT log** — only the actual download
   via `summaryPdf()` records the audit entry.
10. **No storage / no queue** — files are generated and streamed on demand;
    nothing persisted (audit trail lives in `activity_logs`).

---

## 4. IMPLEMENTATION STEPS (ordered; test each before the next — Rule 8)

### Phase R1 — Dependencies + Excel exports

1. `composer require phpoffice/phpspreadsheet:^4.0 dompdf/dompdf:^3.0`
   (installed via direct `composer.json` edit + `composer update` because the
   shell strips `^` on this machine). GD extension enabled first (required by
   PhpSpreadsheet 4.x). Verify both autoload (`class_exists`).
2. NEW `app/Services/ReportExportService.php`:
   - `progressExcel(Project $project): StreamedResponse` — sheet "Progress
     History": columns Month | Inspection Date | Planned % | Actual % |
     Variance % | Remarks | Recorded By | Evidence Files.
   - `baselineExcel(Project $project): StreamedResponse` — sheet "Baseline
     Schedule": Month Label | Planned Date | Planned Progress % | Description.
   - `extensionsExcel(Project $project): StreamedResponse` — sheet
     "Extensions": Extension # | Original End Date | New End Date | Duration
     (days) | Status | Reason | Rejection Reason | Approved At | Approved By.
   - `issuesExcel(Project $project): StreamedResponse` — sheet "Issues":
     Name | Type | Severity | Status | Linked Month | Created | Resolved |
     Resolution Notes.
   - private `streamSpreadsheet(Spreadsheet $spreadsheet, string $filename)`:
     writes via `Xlsx` writer to `php://output` inside a
     `response()->streamDownload()` callback; sets `Content-Type` + filename.
   - private `authorize(Project $project)` — the `show()` authorization block,
     `abort(403)` otherwise.
   - `logExport(Project $project)` — ActivityLog entry.
3. NEW `app/Http/Controllers/ReportExportController.php` (shared, mirrors
   `ProjectController`): thin methods calling the service and returning the
   `StreamedResponse`. Keeps controllers HTTP-only (Rule 5).
4. ROUTES in `routes/web.php` inside each role group (staff, contractor, admin):
   - `GET /projects/{project}/reports/progress.xlsx` → `progressExcel`
   - `GET /projects/{project}/reports/baseline.xlsx` → `baselineExcel`
   - `GET /projects/{project}/reports/extensions.xlsx` → `extensionsExcel`
   - `GET /projects/{project}/reports/issues.xlsx` → `issuesExcel`
   (named `{role}.projects.exports.{kind}`).
5. TEST `tests/Feature/ReportExportTest.php`:
   - each Excel route returns 200 + `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` + correct filename;
   - streamed bytes load back via `IOFactory` → header row + a known data row
     asserted for each of the 4 kinds (use factories);
   - contractor exporting their OWN project succeeds; contractor exporting a
     DIFFERENT project gets 403; unauthenticated request redirects/401;
   - export writes an ActivityLog row with `project_id`.

Verify: `php -l` on changed PHP; `php artisan test --filter=ReportExportTest`.

### Phase R2 — PDF Project Summary

6. NEW `resources/views/reports/project-summary.blade.php` — standalone HTML
   (no layout): header block (code, title, category, contractor, location,
   budget, planned dates, status), stats row (progress %, days remaining, risk
   badge), S-Curve chart container, progress summary table, open issues list,
   extension history table, footer with generated-at. Inline `<style>` only
   (dompdf-safe: no flexbox reliance, use tables/float — dompdf v3 CSS subset).
7. `ReportExportService::summaryPdf(Project $project): StreamedResponse`:
   - loads `$data = app(ProjectDetailService::class)->prepareProjectData($project)`
     (reuses overview/stats/alerts/open_issues assembly);
   - builds the S-Curve SVG polyline from `prepareChartData()` (labels,
     `frozen_planned_data`, `adjusted_planned_data`, `actual_data`,
     `has_extension`, `y_max`) via a small private `buildSvgChart(array $chartData): string`;
   - renders the Blade view, `$dompdf = new Dompdf(); loadHtml($html); setPaper('A4'); render();`
     (bare dompdf — no facade), streams via `Response::streamDownload` with
     `Content-Type: application/pdf`.
   - Fallback: `buildSvgChart()` returns a `.no-chart` div when `ready`/`labels`
     are empty (view hides it) — no CSS-bar chart was needed (SVG renders fine).
8. ROUTE `GET /projects/{project}/reports/summary.pdf` in all three role groups
   (named `{role}.projects.exports.summary`).
9. UI — the "Reports" dropdown in `components/header.blade.php`
   `.header-badges-right` (4 Excel links + 1 PDF link, role-aware since the
   controller enforces auth anyway) + "Export Excel" button in the Progress tab
   card header (`progress.blade.php` line ~10 flexbox header).
10. TEST (extend `ReportExportTest`):
    - summary route returns 200 + `Content-Type: application/pdf`;
    - streamed bytes start with `%PDF-` (dompdf v3 uncompressed header — verified
      working) and are non-trivial length; also assert the ActivityLog row;
    - empty-chart project still produces a valid `%PDF-` stream.

Verify: `php -l`; `php artisan view:cache` compiles the new Blade;
`php artisan test --filter=ReportExportTest`; full `composer test`.

### Phase R2b — PDF summary quality fixes (commits `3e58fe0`, `2ecc0e9`, `52e2f4e`)

11. Progress-tab Blade compile fix — replaced an invalid `@php(...)` one-liner
    with a paired `@php`/`@endphp` block (Blade 12 won't compile the former).
12. SVG closure scope fix — `buildSvgChart` polyline closures `use ($points)`;
    header dropdown CSS clipping fix.
13. **Peso sign** — `.meta-grid .value.peso` must use `'DejaVu Sans'` with
    `font-weight: bold`; the inherited `font-weight: 600` maps to an
    unresolvable `'600'` font subtype and silently falls back to Helvetica
    (no U+20B1 glyph). DejaVuSans-Bold embedded (verified via hex).
14. **S-Curve flat-0% bug identified** — `$points` did `(float)$v` on
    `['x'=>ts,'y'=>val]` arrays → every point became `1.0` → all polylines
    collapsed to y=292.5. Full rewrite landed in R3 step 17.
15. **Latest-inspection selection** — `->reorder()` before `orderByDesc()`
    in `project-summary.blade.php` and `ProjectOverviewService::getOverviewStats()`
    (the `progressHistory()` relation bakes in `orderBy inspection_date asc`,
    so the appended `desc` was a no-op and `first()` returned the earliest
    record). PDF now shows the most recent inspection (2026-05, actual 3.00%).
16. **Legend + month-name labels** — shipped as part of the R3 SVG rewrite
    (step 17).

### Phase R3 — S-Curve rewrite + view-before-download (commit `1bb101a`)

17. `buildSvgChart()` rewritten to accept **both** scalar and associative-array
    points:
    - `y` read from `$v['y']` when the point is an array (else the scalar value);
    - `x` timestamp mapped onto the label grid via `tick_timestamps`
      (`indexForTs`), so a sparse 2-point `actual_data` (Apr/May 2026) lands on
      the correct calendar columns instead of collapsing to x=0/1;
    - legend emitted (Frozen Baseline `#3b82f6`, Adjusted Planned `#8b5cf6`
      dashed, Actual `#10b981`) on a `#f8fafc` rect at the chart top
      (`legendItem()` helper), with `padTop` bumped 25→45 to fit;
    - x-axis labels formatted `Y-m` → `F Y` via `Carbon` (`April 2026`);
    - verified on project 142 real PDF: 65 distinct line y-coordinates (was
      collapsed), legend text + `April 2026` present in decompressed streams.
18. **Inline preview route** — extracted shared `buildSummaryDompdf(Project)`;
    added `summaryPdfView()` returning the same PDF via `streamDownload` with
    the 4th `$disposition = 'inline'` arg (no `Content-Disposition` header
    override — ResponseFactory sets it from the disposition param). No
    audit-log entry for previews.
19. Routes `GET /projects/{project}/reports/summary/view` in all three role
    groups (named `{role}.projects.exports.summary.view`). NOTE: `route:cache`
    was stale — new routes invisible until `php artisan route:cache` re-run.
20. UI — both export dropdowns (header + progress tab) PDF item now calls the
    existing `openDocumentViewer({name, viewUrl: summary.view, downloadUrl:
    summary})` modal (already included on every project-detail page via the
    always-rendered Documents tab). Excel items left as plain links.
21. Tests (ReportExportTest, now 16):
    - S-Curve SVG: ≥2 distinct polyline y-coords (non-flat), legend labels,
      `April 2026` / `June 2026` month names;
    - `summaryPdfView`: 200 + `application/pdf` + `Content-Disposition: inline`
      + NO ActivityLog row;
    - `summaryPdf`: `attachment` disposition + ActivityLog row still written.

### Final verification (after all phases)

- `php -l` on every changed PHP file.
- `php artisan route:list` shows the 18 export routes (6 × 3 roles: 5 export +
  1 inline view).
- `php artisan view:cache` — new Blade compiles.
- Full suite `php artisan test` — no regressions (**331 passed / 1150 assertions**
  as of `1bb101a`).
- Browser check (manual, on request): Reports dropdown previews the summary PDF
  in the viewer modal and downloads on button click; Excel downloads all 4
  files per role.

---

## 5. FILES TOUCHED

NEW:
- `textfiles/report_generation_plan.md` (this file)
- `app/Services/ReportExportService.php`
- `app/Http/Controllers/ReportExportController.php`
- `resources/views/reports/project-summary.blade.php`
- `tests/Feature/ReportExportTest.php`

MODIFIED:
- `composer.json` / `composer.lock` (2 new dependencies)
- `routes/web.php` (export routes in 3 role groups + inline summary.view routes)
- `resources/views/projects/project_detail/components/header.blade.php` (Reports dropdown)
- `resources/views/projects/project_detail/components/tabs/progress.blade.php` (Export Excel button)
- `app/Http/Controllers/ReportExportController.php` (+ `summaryPdfView`)

NOT touched:
- No migrations (pure read + generate; no schema change).
- No new `public/` assets (buttons are plain `<a>` links; no JS needed).
- `ProjectChartService` / `ProjectDetailService` (read-only reuse; no changes).
- No queue, no storage writes.
- `components/document-viewer-modal.blade.php` — reused as-is (no changes).
- **Excel generation code intentionally unchanged** (corruption issue unresolved,
  see §6.4).

---

## 6. OPEN QUESTIONS / STILL TO RESOLVE

1. **Which Excel columns matter to the client?** Proposed columns above are a
   sensible default from the models. Confirm before R1 (easy to adjust).
2. **Contractor export scope** — decision 6 allows contractors to download their
   own project's reports (read-only). If the client wants contractors to NOT see
   reports, gate contractor exports off entirely.
3. **PDF S-Curve fidelity** — inline SVG is close to the browser chart but
   simpler (no tooltips/interaction). Acceptable for a printed report.
4. **Excel corruption report (BLOCKING Excel work)** — a client reported a
   downloaded `.xlsx` as corrupt, but every local reproduction round-trips
   through `IOFactory::load` cleanly (tests pass; `PK\x03\x04` magic verified;
   cells assert correctly). Root cause never identified. **Decision: Excel
   exports are frozen — do NOT add the HTML-table preview / viewer flow for
   Excel, and do NOT touch Excel generation code, until the corruption issue is
   reproduced and fixed.** Revisit with a real client file + browser-download
   capture if one becomes available.
5. **Summary PDF scope** — currently one combined document. A separate
   extension-history PDF could be added later if requested (data is ready).

---

## 7. PROCESS NOTES

- Rule 12: work only on `core/report-generation`; do NOT merge to main without
  explicit user approval.
- Rule 8: test after each phase (R1 then R2).
- Rule 10: inform the user after each phase completes.
- Rule 11: log progress in §8 as each phase lands.
- Plan-only commit BEFORE implementation (established pattern: Phase A–E and
  N1–N5 plans were committed before any code).
- This branch contains only the plan in its first commit; no implementation in
  the plan commit.

---

## 8. SESSION / CHANGES LOG

### 2026-08-05 — Plan created

- Branch `core/report-generation` created from `main` @ `74da1a4`.
- Codebase study complete: models (Project/Progress/Baseline/Extension/Issue),
  chart data source (`ProjectChartService::prepareChartData`), detail header
  button slot, download precedents, route groups, factories, test conventions.
- Environment verified: composer + packagist reachable; `zip`/`xmlwriter`
  present; **GD absent** → PDF chart must be SVG, not PNG.
- Confirmed decisions: `phpspreadsheet` (Excel) + `laravel-dompdf` (PDF), one
  shared controller + service across the three role groups, contractor scoped
  to own project, inline-SVG S-Curve with CSS-bar fallback.
- Plan written (this file); committed as plan-only; no implementation yet.

### 2026-08-05 — R1 implemented (commit `c47b9ab`)

- Enabled GD (`extension=gd` in `C:\xampp\php\php.ini`) — required by
  PhpSpreadsheet 4.x; verified via `php -m`.
- **Deviation:** `barryvdh/laravel-dompdf` incompatible with Laravel 12 → used
  **`dompdf/dompdf:^3.0`** directly + **`phpoffice/phpspreadsheet:^4.0`**.
  Composer constraints set by editing `composer.json` (shell strips `^`).
- `app/Services/ReportExportService.php` — 4 Excel exporters + `summaryPdf` +
  private helpers (`streamSpreadsheet`, `writeHeaderRow`, `fileName`,
  `logExport`, `authorize`, `buildSvgChart`). No storage writes; streams to
  `php://output`; ActivityLog audit row per export.
- `app/Http/Controllers/ReportExportController.php` — thin shared controller
  (admin/staff/contractor), auth enforced inside service.
- `routes/web.php` — 5 export routes × 3 role groups (`{role}.projects.exports.*`).
  Note: `php artisan route:list` silently omits new routes until `route:clear`
  is run (stale route cache).
- `tests/Feature/ReportExportTest.php` — 10 tests green (Excel cell assertions
  via `IOFactory` from captured stream; StreamedResponse body captured with
  `sendContent()` inside `ob_start`; 403 via `HttpException::getStatusCode()`).
- Full suite: **325 passed**.

### 2026-08-05 — R2 implemented (commit `aae78cd`)

- `resources/views/reports/project-summary.blade.php` — standalone dompdf-safe
  view (tables, inline CSS, inline SVG chart, status pills, open issues +
  extension tables, generated-at footer).
- `summaryPdf` renders via bare `Dompdf` (loadHtml → setPaper A4 → render) and
  streams with `application/pdf`; SVG chart built server-side from
  `prepareChartData()` (frozen/adjusted planned + actual polylines).
- UI: **Reports dropdown** in `components/header.blade.php`
  `.header-badges-right` (role-aware named routes: `route($role . '.projects.exports.{kind}', $project->id)`) + **Export dropdown** in the Progress tab
  card header (`progress.blade.php`) — Bootstrap 5.3 dropdowns already loaded.
- PDF tests added (magic header `%PDF-`, content-type, ActivityLog); note:
  dompdf v3 compresses content streams so the plan's raw `str_contains` text
  assertion is replaced by a non-trivial-length check.
- Full suite: **325 passed**.

### 2026-08-05 — PDF-fix round 1 (commits `3e58fe0`, `2ecc0e9`, `52e2f4e`)

After the R2 PDF was reviewed on a real project, three defects surfaced and
were fixed:

- **Progress-tab Blade compile error** (`3e58fe0`) — replaced a one-line
  `@php(...)` construct with a paired `@php`/`@endphp` block.
- **SVG closure scope + dropdown clipping** (`2ecc0e9`) — polyline closures
  gained `use ($points)`; header dropdown CSS fixed.
- **Peso sign (₱) missing** (`52e2f4e`) — `.value.peso` switched to
  `'DejaVu Sans'` + `font-weight: bold` (the inherited `600` maps to an
  unresolvable font subtype → silent Helvetica fallback without U+20B1).
  DejaVuSans-Bold confirmed embedded via hex in the stream.
- **S-Curve rendered flat at 0%** — root cause: `$points` did `(float)$v` on
  associative-array points (`['x'=>ts,'y'=>val]`) → every point `(float)[] == 1.0`
  → all lines collapsed to y=292.5. (Full rewrite landed in `1bb101a`.)
- **Latest inspection showed the FIRST record** — the `progressHistory()`
  relation bakes in `orderBy inspection_date asc`; the appended `orderByDesc`
  was a no-op until `->reorder()` was added in the view AND
  `ProjectOverviewService::getOverviewStats()`. PDF now shows 2026-05 (3.00%).
- 3 new tests (`renderSummaryView()` helper + peso/latest-inspection/data-URI
  assertions). Full suite: **328 passed / 1133 assertions**.

### 2026-08-05 — S-Curve rewrite + inline PDF viewer (commit `1bb101a`)

User report after the first fix round: S-Curve still flat with no legend, and
users want to **view files before downloading**. Work completed:

- `buildSvgChart()` rewritten:
  - reads `y` from `$v['y']` for array points (scalar values still supported);
  - maps each point's `x` timestamp onto the label grid via `tick_timestamps`
    so sparse `actual_data` lands on the correct calendar columns;
  - emits a legend (Frozen Baseline / Adjusted Planned / Actual) with
    `legendItem()` swatches; `padTop` 25→45 for legend headroom;
  - x-axis labels now `Y-m` → month-name format (`April 2026`) via Carbon;
  - verified on project 142's real PDF: **65 distinct line y-coordinates**,
    legend text + `April 2026` present in decompressed streams (was flat).
- **View-before-download (PDF only):** extracted `buildSummaryDompdf()`;
  added `summaryPdfView()` → same PDF with `Content-Disposition: inline`
  (4th `$disposition` arg to `Response::streamDownload`) and **no audit log**.
  New routes `{role}.projects.exports.summary.view` × 3 roles.
- **UI:** both export dropdowns (header + Progress tab) now open the existing
  document-viewer modal (`openDocumentViewer`) for the PDF summary; Excel items
  intentionally left as direct download links (corruption issue — see §6.4).
- Verified end-to-end via HTTP: `/staff/projects/142/reports/summary/view`
  → 200, `application/pdf`, `inline; filename=prj-2026-00037_project-summary.pdf`,
  13,034 bytes `%PDF-1.7`.
- Tests: +3 (non-flat S-Curve with legend + month names; inline view serves PDF
  with no audit log; download still logs). **ReportExportTest: 16 passed /
  63 assertions. Full suite: 331 passed / 1150 assertions.**

### Remaining

- Manual browser check (optional): Reports dropdown previews the summary PDF in
  the viewer modal, Download button streams the attachment; Excel downloads all
  4 files per role.
- Resolve the **Excel corruption report** (§6.4) before touching Excel again.
- Merge to `main` requires explicit approval (Rule 12).

---

END OF REPORT GENERATION PLAN

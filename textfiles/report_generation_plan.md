# REPORT GENERATION — PDF / Excel Exports (Gap #10)

CSPC PMU System — textfiles/gaps_and_features_missing.txt item #10

Status: ⬜ NOT STARTED — plan-only commit on branch `core/report-generation`
        (created from `main` @ `74da1a4`). No implementation yet.

Date: August 5, 2026

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
**GD NOT installed** (so charts in the PDF must be rendered as **inline SVG**,
not PNG — dompdf ≥2 ships a built-in SVG image renderer usable via
`data:image/svg+xml;base64,...`). Packagist reachable (HTTP 200) so
`composer require` will work.

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

1. **Library — Excel:** `phpoffice/phpspreadsheet` (^2.0) directly. Native
   `.xlsx` via `Xlsx` writer; streams to `php://output`. No facade wrapper
   (keeps the service layer plain; mirrors the project's bare-service style).
2. **Library — PDF:** `barryvdh/laravel-dompdf` (^3.0, Laravel 12-compatible).
   Renders a dedicated Blade template to PDF; streams the result.
3. **One shared controller + one shared service** registered in all three role
   groups — mirrors `ProjectController`. Per-role authorization enforced inside.
4. **PDF S-Curve = inline SVG** built server-side from
   `ProjectChartService::prepareChartData()` (labels + frozen/adjusted planned +
   actual + extension). Fallback if dompdf's SVG renderer misbehaves: an HTML/CSS
   bar table of the same data (documented, test both in R2).
5. **Routes** — one route per report type, named per role:
   `{role}.projects.exports.progress` etc. Controller methods
   `progressExcel / baselineExcel / extensionsExcel / issuesExcel / summaryPdf`.
6. **Auth** — admin/staff any project; contractor own project only (reuses the
   exact `show()` authorization block).
7. **UI** — a "Reports" dropdown (Export Excel → 4 items, View PDF Summary) in
   the project header `.header-badges-right`, plus a small inline
   "Export Excel" button in the Progress tab card header (progress.blade.php
   card header already uses flexbox `justify-content: space-between`).
8. **File naming** — `{project_code}_progress.xlsx`,
   `{project_code}_baseline.xlsx`, `{project_code}_extensions.xlsx`,
   `{project_code}_issues.xlsx`, `{project_code}_project-summary.pdf`.
9. **Activity log** — record an `ActivityLog::create` (action `Export Report`,
   project_id set) on each export, consistent with the audit-trail pattern.
10. **No storage / no queue** — files are generated and streamed on demand;
    nothing persisted (audit trail lives in `activity_logs`).

---

## 4. IMPLEMENTATION STEPS (ordered; test each before the next — Rule 8)

### Phase R1 — Dependencies + Excel exports

1. `composer require phpoffice/phpspreadsheet:^2.0 barryvdh/laravel-dompdf:^3.0`.
   Verify both autoload (`php artisan tinker` `class_exists`).
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
     `has_extension`) via a small private `buildSvgChart(array $chartData): string`;
   - renders the Blade view, `$pdf = Pdf::loadHTML($html)->setPaper('a4')`,
     streams to `php://output` with `application/pdf`.
   - Fallback: if the SVG chart throws or renders empty, call
     `buildHtmlChart()` (CSS bars) instead — always produce a chart.
8. ROUTE `GET /projects/{project}/reports/summary.pdf` in all three role groups
   (named `{role}.projects.exports.summary`).
9. UI — the "Reports" dropdown in `components/header.blade.php`
   `.header-badges-right` (4 Excel links + 1 PDF link, role-aware since the
   controller enforces auth anyway) + "Export Excel" button in the Progress tab
   card header (`progress.blade.php` line ~10 flexbox header).
10. TEST (extend `ReportExportTest`):
    - summary route returns 200 + `Content-Type: application/pdf`;
    - streamed bytes start with `%PDF-` and contain the project title/code
      (dompdf embeds text as PDF objects — assert via `str_contains` on the
      raw stream or, more robustly, assert it is a non-empty valid PDF header
      plus metadata we set explicitly);
    - `buildSvgChart()` returns an `<svg>` containing planned+actual polylines
      for a project with history; returns a `<div>` bar fallback for an
      empty-chart project.

Verify: `php -l`; `php artisan view:cache` compiles the new Blade;
`php artisan test --filter=ReportExportTest`; full `composer test`.

### Final verification (after all phases)

- `php -l` on every changed PHP file.
- `php artisan route:list` shows the 15 new export routes (5 × 3 roles).
- `php artisan view:cache` — new Blade compiles.
- Full suite `php artisan test` — no regressions (re-baseline count vs main).
- Browser check (manual, on request): Reports dropdown downloads all 5 files
  per role; Excel opens with correct rows; PDF renders header + chart + tables.

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
- `routes/web.php` (export routes in 3 role groups)
- `resources/views/projects/project_detail/components/header.blade.php` (Reports dropdown)
- `resources/views/projects/project_detail/components/tabs/progress.blade.php` (Export Excel button)

NOT touched:
- No migrations (pure read + generate; no schema change).
- No new `public/` assets (buttons are plain `<a>` links; no JS needed).
- `ProjectChartService` / `ProjectDetailService` (read-only reuse; no changes).
- No queue, no storage writes.

---

## 6. OPEN QUESTIONS / STILL TO RESOLVE

1. **Which Excel columns matter to the client?** Proposed columns above are a
   sensible default from the models. Confirm before R1 (easy to adjust).
2. **Contractor export scope** — decision 6 allows contractors to download their
   own project's reports (read-only). If the client wants contractors to NOT see
   reports, gate contractor exports off entirely.
3. **PDF S-Curve fidelity** — inline SVG is close to the browser chart but
   simpler (no tooltips/interaction). Acceptable for a printed report.
4. **Summary PDF scope** — currently one combined document. A separate
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

---

END OF REPORT GENERATION PLAN

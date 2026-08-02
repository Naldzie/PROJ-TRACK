# PHASE C — Issue Lifecycle Upgrade

CSPC PMU System — Core Project Monitoring (core_monitoring_completion_plan.md Phase C)

Status: 📋 PLANNED — not started (branch `core/issue-lifecycle` created from `main` @ `dfbc2ff`; plan-only commit follows)

Branch: `core/issue-lifecycle` (created from `main` @ `dfbc2ff` — merged Phase A + Phase B)

Date: August 2, 2026

Parent plan: textfiles/core_monitoring_completion_plan.md (Phase C, §6)
Rules: textfiles/active_reference/project_context_and_development_rules.txt (Rule 8/10/11/12)

---

## 1. OBJECTIVE

Upgrade the Issues feature so each issue is a complete, audited record:

- Link an issue to a **baseline month** (`baseline_schedule_id` → `project_baseline_schedules`) so
  issues are tied to the schedule they belong to (groundwork for the Phase 10 feedback loop).
- Capture **resolution notes** when an issue is Resolved/Closed (required), reusing the
  already-managed `resolved_at` date.
- Attach **evidence files** to issues — `context: 'issue'` (issue photo/doc) and
  `context: 'resolution'` (resolution proof) — via the Phase A attachments infra.
- Expose the new fields + attachments in the `getIssues()` JSON and render them in the
  Issues tab table + Issues modal cards (with preview via `document-viewer-modal`).
- **ActivityLog on store/update/destroy** (the only issue write-path still missing audit).

Base change (verified with user): Phase C branches from **`main`** (merged A+B), NOT from the old
`core/attachments-infra` branch. The parent plan §9 table is updated accordingly in this plan.

---

## 2. CURRENT STATE & PATTERNS TO MIRROR (verified 2026-08-02)

Codebase study completed. Everything below was verified on disk.

| Concern | Reference | Pattern |
|---|---|---|
| Migration | `database/migrations/2026_07_18_172127_add_issue_type_severity_resolved_at_to_issues_table.php` | `Schema::table('issues', ...)`, `$table->after()`, nullable FK columns |
| Model | `app/Models/Issue.php` | `HasFactory`, `$fillable`, `$casts`, `project()` belongsTo, `scopeUnresolved` |
| Baseline source | `app/Models/ProjectBaselineSchedule.php` | `month_label` + `planned_date` (date cast); `scopeOrderByMonth()` = `orderBy('planned_date')`; table write-once |
| Attachments | `app/Models/Attachment.php` | `CONTEXTS = ['inspection','issue','resolution']`; `ALLOWED_ATTACHABLE_TYPES` already includes `Issue::class`; `project_id` denormalized; inverse `morphMany` NOT yet wired on Issue |
| Upload endpoint | `app/Http/Controllers/AttachmentController.php` | `upload()` validates `project_id`/`attachable_type`/`attachable_id`/`context`/`file`; guards attachable belongs to project (422); policy 403 (admin+staff); ActivityLog with `project_id` |
| Policy | `app/Policies/AttachmentPolicy.php` | `view` delegates to project view; `create`/`delete` admin+staff |
| Routes | `routes/web.php` lines 179–186 (staff), 293–294 (contractor), 437–444 (admin) issues; 224–234 / 311–315 / 516–526 attachments | issues: list/store/update/destroy per role; attachments: upload/delete/view/download per role (contractor view/download only) |
| IssueController | `app/Http/Controllers/IssueController.php` | `getIssues()` JSON (no new fields); store/update auto-manage `resolved_at`; **no ActivityLog calls**; `issue->project_id` guard on update/destroy |
| Issues modal | `resources/views/projects/project_detail/components/modals/issues_modal.blade.php` | add form POSTs via `FormData` (ready for file inputs); `editIssue()` / `openEditIssueModal()` pre-fill 6 fields; cards rendered by `displayIssues()` |
| Issues tab | `resources/views/projects/project_detail/components/tabs/issues.blade.php` | table rendered by `displayTabIssues()`; summary counters; edit/delete via `getIssuesApiUrl(id)` |
| Document viewer | `resources/views/components/document-viewer-modal.blade.php` | `window.openDocumentViewer({ id, name, type, size, uploadedBy, uploadedAt, viewUrl, downloadUrl })`; **included only in `documents.blade.php:167`** — NOT on the issues tab yet |
| JS globals | `resources/views/projects/project_detail/project_detail.blade.php` | `window.projectId`, `window.csrfToken`, `window.userRole`, `getIssuesApiUrl(action)` at line 158 |
| ActivityLog | `app/Models/ActivityLog.php` | `$fillable` incl. `project_id`; pattern: `ActivityLog::create(['user_id', 'project_id', 'action', 'description', 'ip_address'])` |

### Verified unknowns discovered during study

1. **Duplicate `editIssueModal`** — `id="editIssueModal"` exists BOTH inline in
   `project_detail.blade.php:102` (name/description/status only) AND in
   `issues_modal.blade.php:98` (name/description/status/type/severity). Both are included on the
   same page. `getElementById` resolves to the FIRST in DOM order (issues_modal comes first at
   `project_detail.blade.php:99`, inline at 102), so it works today but is fragile duplicated
   markup. Phase C should delete the inline duplicate in `project_detail.blade.php` (lines
   101–146) and keep the richer modal.
2. **`scopeOpen` vs `scopeUnresolved`** — `Issue` already has `scopeUnresolved()`
   (`whereIn('status', ['Open','In Progress'])`). Parent plan asks for `scopeOpen` "for
   unresolved". To avoid duplication, define `scopeOpen()` as the semantic alias the overview
   "Open Issues" card uses (Open + In Progress) OR mirror exactly `scopeUnresolved`. Decide at
   implementation; default = alias to the same `whereIn` (keeps both names meaningful).
3. **Attachments on a NEW issue** — `attachable_id` requires the issue id, which doesn't exist
   until `store()` returns. So attachment upload for a new issue must happen **after** create,
   via a second call to the existing `/attachments/upload` endpoint (context `issue` /
   `resolution`). The add form is already `FormData`-based; the JS will upload chosen files per
   context after the store succeeds. (Alternative — extend `IssueController::store` to accept
   files and persist them — rejected: duplicates `AttachmentController::upload`'s validation +
   policy + storage logic; the separate endpoint is the codebase convention.)

---

## 3. CONFIRMED DECISIONS

1. **`baseline_schedule_id`** — nullable FK → `project_baseline_schedules`, `onDelete('null')`
   (nullOnDelete), indexed. Validated to belong to the same project on store/update.
2. **`resolution_notes`** — `text`, nullable column; **required** whenever status is
   `Resolved` or `Closed` (store + update). `resolved_at` remains auto-managed by the existing
   controller logic (reused as the resolution date — parent decision 6).
3. **Attachments** — reuse Phase A infra as-is. Issue model gains inverse
   `attachments()` morphMany. Contexts `issue` and `resolution` only (not `inspection`).
4. **New-issue attachments upload after create** — issue created first, then files uploaded via
   the existing `/attachments/upload` endpoint (decision 3 in §2 above).
5. **ActivityLog** — add `ActivityLog::create(...)` to store / update / destroy with
   `project_id` (Phase B already populated `activity_logs.project_id`; IssueController is the
   last issue write-path missing audit).
6. **Duplicate edit modal** — remove the inline `editIssueModal` block from
   `project_detail.blade.php`; the `issues_modal.blade.php` one (with type/severity + new
   fields) is canonical.
7. **JSON payload** — `getIssues()` adds `resolved_at`, `resolution_notes`,
   `baseline_schedule_id`, linked `month_label`, and an `attachments` array (id, original_name,
   context, file_url, display_size, uploaded_by) for chips.
8. **Document viewer** — include `components.document-viewer-modal` on the Issues tab and wire
   attachment chips to `window.openDocumentViewer(...)` using the role-scoped attachment
   view/download routes.

---

## 4. IMPLEMENTATION STEPS (ordered; test each before the next — Rule 8)

### Step 1 — Migration

NEW: `database/migrations/<timestamp>_add_baseline_and_resolution_to_issues_table.php`

| Column | Type | Notes |
|---|---|---|
| `baseline_schedule_id` | bigint unsigned nullable | `after('severity')`, `index()`; `$table->foreign(...)->references('id')->on('project_baseline_schedules')->nullOnDelete()` |
| `resolution_notes` | text nullable | `after('baseline_schedule_id')` |

`down()` drops both columns.

Verify: `php artisan migrate` on a scratch DB / `php artisan test` migration runs in-memory.

### Step 2 — Model

MODIFIED: `app/Models/Issue.php`

- `$fillable` += `baseline_schedule_id`, `resolution_notes`.
- Relationships:
  - `baselineSchedule()` → `belongsTo(ProjectBaselineSchedule::class)`
  - `attachments()` → `morphMany(Attachment::class, 'attachable')`
- Scope:
  - `scopeOpen($query)` → unresolved alias (`whereIn('status', ['Open','In Progress'])` —
    resolve duplication with `scopeUnresolved` per §2 finding 2; default = same body).
- (no cast changes needed; `resolution_notes` is text, `baseline_schedule_id` int).

### Step 3 — Controller

MODIFIED: `app/Http/Controllers/IssueController.php`

- `getIssues()` — map adds:
  - `resolved_at` → formatted or `null`
  - `resolution_notes`
  - `baseline_schedule_id`
  - `month_label` → `$issue->baselineSchedule?->month_label`
  - `attachments` → `$issue->attachments->map(...)` with `id, original_name, context, file_url,
    display_size, uploaded_by`
  - eager-load `baselineSchedule` + `attachments` on the issues query.
- `store()` — validate adds:
  - `baseline_schedule_id` → `nullable|integer|exists:project_baseline_schedules,id`
  - `resolution_notes` → `required_if:status,Resolved` + `required_if:status,Closed` (string)
  - Post-validate guard: if `baseline_schedule_id` set, confirm the baseline belongs to
    `$project->id` (e.g. `ProjectBaselineSchedule::where('id', $id)->where('project_id', $project->id)->exists()`),
    else 422.
  - `ActivityLog::create([... 'action' => 'Create Issue', 'description' => "{name} created issue \"{$issue->issue_name}\" for project \"{$project->title}\".", 'project_id' => $project->id, 'ip_address' => $request->ip()])`.
- `update()` — same validation adds + guard; `ActivityLog::create('Update Issue')`.
- `destroy()` — `ActivityLog::create('Delete Issue')` (capture name before `$issue->delete()`);
  attachments cascade via morph delete? — attachments use `attachable_id` (no FK cascade on the
  morph pair; Attachment `booted()` removes physical files only on its own delete). Decide: soft
  cleanup of issue attachments on destroy — either delete them explicitly in `destroy()` or rely
  on the parent plan's "Issue model gets attachments() morphMany" + add explicit cleanup. Default:
  explicitly delete the issue's attachment records in `destroy()` before `$issue->delete()` so
  physical files are removed too (the model hook won't run otherwise).

### Step 4 — Views (modal)

MODIFIED: `resources/views/projects/project_detail/components/modals/issues_modal.blade.php`

- **Add form** (`addIssueForm`):
  - Baseline month dropdown: `<select id="issueBaselineId" name="baseline_schedule_id">` with
    options from `$project->baselineSchedules()->orderBy('planned_date')->get()` →
    `value="{{ $bs->id }}"` label `{{ $bs->month_label }}` (blade `@php $baselines = ... @endphp`
    at top of the file; `$project` is in scope via the include at `project_detail.blade.php:99`).
    Empty option "— None —".
  - Resolution notes textarea `#issueResolutionNotes` (shown only when status is
    Resolved/Closed — toggle in JS on `#issueStatus` change).
  - File inputs: `#issueAttachmentFiles[]` (multiple, context `issue`) and
    `#resolutionAttachmentFiles[]` (multiple, context `resolution`) — accepted
    `image/*,.pdf` per Attachment mimes.
  - JS submit handler: after `store()` returns `issue.id`, upload each chosen file per context to
    `/attachments/upload` (FormData: `project_id`, `attachable_type=App\Models\Issue`,
    `attachable_id=issue.id`, `context`, `file`), then reload.
- **Edit modal** (canonical, replaces inline duplicate):
  - Same baseline dropdown + resolution notes textarea + file inputs.
  - `editIssue()` / `openEditIssueModal()` signatures grow to accept
    `baseline_schedule_id`, `resolution_notes`; pre-fill them; toggle notes visibility on
    status change.
  - Cards (`displayIssues`) render attachment chips (per attachment, per context) that call
    `window.openDocumentViewer(...)`; and show month + resolution info on the card.
  - `displayIssues()` uses the `attachments` array from JSON.

### Step 5 — Views (tab)

MODIFIED: `resources/views/projects/project_detail/components/tabs/issues.blade.php`

- Table (`displayTabIssues`): add **Month** column (from `issue.month_label` or "—") and
  **Resolution** column (notes preview + resolved date when resolved).
- Issue cards: attachment chips with preview (reuse `document-viewer-modal`).
- Add `@include('components.document-viewer-modal')` to the issues tab (mirror
  `documents.blade.php:167`).

### Step 6 — Remove duplicate edit modal

MODIFIED: `resources/views/projects/project_detail/project_detail.blade.php`

- Delete the inline `editIssueModal` block (lines 101–146) — the `issues_modal.blade.php`
  version is canonical and stays.

### Step 7 — Tests

NEW: `tests/Feature/IssueLifecycleTest.php` (RefreshDatabase; `User::create([...])` per
environment finding; direct controller invocation; `UploadedFile::fake()->createWithContent()`
with real JPEG/PDF magic bytes — GD not installed).

Cases:
1. `test_resolution_notes_required_when_resolved_or_closed` — store with status Resolved + no
   notes → 422; with notes → 201/success. Same on update.
2. `test_baseline_schedule_must_belong_to_project` — baseline of another project → 422;
   valid baseline → success.
3. `test_attachments_attach_to_issue_and_resolution` — upload context `issue` and `resolution`
   bound to an issue; `$issue->attachments` returns both with correct contexts.
4. `test_get_issues_exposes_new_fields` — JSON contains `resolved_at`, `resolution_notes`,
   `baseline_schedule_id`, `month_label`, `attachments[]`.
5. `test_activity_log_created_on_store_update_destroy` — ActivityLog rows with `project_id` and
   actions Create/Update/Delete Issue.
6. `test_issue_destroy_cleans_attachments` — issue with attachments deleted → attachment rows +
   physical files gone.
7. `test_scope_open_returns_open_and_in_progress`.

Existing `AttachmentTest` + `OverviewTest` + `ActivityVisibilityTest` must stay green (full-suite
baseline on this branch: 233 passed / 798 assertions).

### Step 8 — Verification

- `php -l` on every changed file.
- `php artisan test --filter=IssueLifecycleTest` — all green.
- Full suite `php artisan test` — no regressions.
- `php artisan route:list` — attachment routes unchanged; issue routes unchanged.
- Browser check (manual, on request): add issue with baseline month + resolution notes + files;
  edit; delete; chips preview in modal + tab.

---

## 5. FILES TOUCHED

NEW:
- `database/migrations/<timestamp>_add_baseline_and_resolution_to_issues_table.php`
- `tests/Feature/IssueLifecycleTest.php`

MODIFIED:
- `app/Models/Issue.php`
- `app/Http/Controllers/IssueController.php`
- `resources/views/projects/project_detail/components/modals/issues_modal.blade.php`
- `resources/views/projects/project_detail/components/tabs/issues.blade.php`
- `resources/views/projects/project_detail/project_detail.blade.php` (remove inline duplicate
  edit modal)

NOT touched:
- `app/Models/Attachment.php` (inverse morphMany belongs on Issue, not Attachment)
- `app/Http/Controllers/AttachmentController.php` (upload/delete/view/download already ready)
- `routes/web.php` (no route changes needed)

---

## 6. OPEN QUESTIONS / STILL TO RESOLVE

1. **`scopeOpen` semantics** — alias of `scopeUnresolved` (Open + In Progress) vs `status='Open'`
   only. Default: alias (matches "Open Issues" summary card). Confirm at implementation.
2. **Issue-destroy attachment cleanup** — explicit `Attachment::where(...)->delete()` loop in
   `destroy()` (recommended, so physical files are removed) vs leaving orphans. Default:
   explicit cleanup.
3. **Edit form file re-upload** — on edit, only NEWLY chosen files are uploaded (existing
   attachments are shown as chips with delete via the existing `/attachments/{id}` DELETE route).
   No replacement semantics. Confirm acceptable.
4. **Resolution-notes on update** — `required_if` on status Resolved/Closed means editing a
   resolved issue without notes fails. The form pre-fills existing notes, so this is fine;
   confirm the UX (notes textarea always visible on edit of a resolved issue).
5. **`month_label` value vs full date** — dropdown shows `month_label`; JSON exposes
   `month_label`; table Month column shows it. Confirm no date formatting needed.

---

## 7. PROCESS NOTES

- Rule 12: work only on `core/issue-lifecycle`; do NOT merge to main without explicit user
  approval.
- Rule 8: test after each step (Step 1 → 8 in order).
- Rule 10: inform the user after the phase completes.
- Rule 11: log progress in §8 as each step lands.
- Plan-only commit BEFORE implementation (established pattern: Phase A/B plans were committed
  before any code).

---

## 8. SESSION / CHANGES LOG

(Chronological log of what is built and any bugs found — append as the phase is implemented.)

### 2026-08-02 — Plan created
- Branch `core/issue-lifecycle` created from `main` @ `dfbc2ff` (merged Phase A + B).
- Codebase study complete (models, controller, views, routes, policies, migrations verified).
- Plan written (this file); committed as plan-only (no implementation).
- Parent plan §9 updated: Phase C + D base changed from `core/attachments-infra` → `main`.

### 2026-08-02 — Phase implemented (user approved "then please start")
- Step 1: Migration `database/migrations/2026_08_02_000003_add_baseline_and_resolution_to_issues_table.php` — `baseline_schedule_id` (nullable FK → project_baseline_schedules, nullOnDelete, constrained via `->constrained()->nullOnDelete()`), `resolution_notes` (text, nullable, after baseline_schedule_id). `down()` drops both (dropConstrainedForeignId + dropColumn).
- Step 2: `app/Models/Issue.php` — `$fillable` += `baseline_schedule_id`, `resolution_notes`; `baselineSchedule()` belongsTo(ProjectBaselineSchedule); `attachments()` morphMany(Attachment, 'attachable'); `scopeOpen()` = `whereIn('status', ['Open','In Progress'])` (alias of scopeUnresolved — open question 1 resolved as the Overview-card semantics).
- Step 3: `app/Http/Controllers/IssueController.php` — `getIssues()` eager-loads baselineSchedule + attachments and exposes `resolved_at`, `resolution_notes`, `baseline_schedule_id`, `month_label`, `attachments[]`; store/update validate `baseline_schedule_id` (nullable, exists) + `resolution_notes` `required_if:status,Resolved|required_if:status,Closed` + manual guard that the baseline belongs to the project (422 JSON); `ActivityLog::create` on store ('Create Issue'), update ('Update Issue'), destroy ('Delete Issue'), all with `project_id`; destroy() explicitly deletes the issue's attachment rows first (physical files removed via the model hook); store/update response payloads also expose the new fields. Unused `Attachment` import removed after implementation.
- Step 4: `issues_modal.blade.php` — `@php` block loads `$project->baselineSchedules()` (ordered by planned_date); add + edit forms get baseline-month dropdown, resolution-notes textarea (toggled via `toggleIssueResolutionFields()` on status change), and file inputs for context `issue` + `resolution`; add/edit submit handlers upload chosen files AFTER the issue is created/updated via the existing `/attachments/upload` endpoint (`attachable_type=App\Models\Issue`); cards show baseline month, resolution block (notes + resolved date), and attachment chips (`renderAttachmentChips`/`openIssueAttachmentById` → `window.openDocumentViewer`). New `.attachment-chip` CSS.
- Step 5: `issues.blade.php` — table gains Month, Resolution, Attachments columns; openEditIssueModal signature extended with `baselineScheduleId` + `resolutionNotes`; REMOVED the duplicate `editIssueForm` submit listener (it double-submitted alongside the modal's own handler — pre-existing bug).
- Step 6: `project_detail.blade.php` — removed the inline duplicate `editIssueModal` block (duplicate-ID bug); the canonical modal in `issues_modal.blade.php` (now with type/severity + new fields) stays.
- Step 7: `tests/Feature/IssueLifecycleTest.php` — 12 tests / 60 assertions. NOTE: store/update catch ValidationException internally and return 422 JSON (not a thrown exception), so the resolution-required tests assert the 422 response shape with `(array) $response->getData()->errors`; `getData()->errors` is a stdClass.
- Step 8: Verification — `php -l` clean on all changed PHP files; `php artisan test --filter=IssueLifecycleTest` 12/12; full suite 245 passed / 858 assertions (baseline 233/798 — no regressions); `php artisan view:cache` compiles all Blade templates.
- Deviations from plan (documented): (a) NO extra `@include('components.document-viewer-modal')` on the issues tab — the modal is already on the page via `documents.blade.php:167` (all tabs render together on `project_detail.blade.php`), so adding a second include would duplicate the `documentViewerModal` element ID. (b) Baseline dropdown is server-rendered from `$project->baselineSchedules()` via a `@php` block (available since the include has `$project` in scope). (c) `resolution_notes` validation uses `required_if` on BOTH `Resolved` and `Closed` (chained) rather than a single conditional.

### 2026-08-02 — Adjustment: baseline month display format (user request)
- User asked the baseline month selection to use "month name-YYYY" (e.g. "June-2026") instead of the raw `Y-m` label.
- `app/Models/ProjectBaselineSchedule.php` — added `month_display` accessor (`Carbon::createFromFormat` on `Y-m`/`Y-m-d` → `F-Y`, with parse fallback).
- `issues_modal.blade.php` — add + edit dropdown options now render `{{ $baseline->month_display }}`; issue cards display `issue.month_display`.
- `issues.blade.php` — table Month column displays `issue.month_display || issue.month_label`.
- `IssueController::getIssues()` — JSON adds `month_display` (raw `month_label` kept as the canonical identifier).
- `IssueLifecycleTest` — asserts `month_display === 'June-2026'` for a `2026-06` baseline. Full suite 245 passed / 859 assertions.
- NOTE: `month_label` column/value is untouched app-wide — only display formatting changes; the existing `ProjectOverviewService::formatMonthLabelForDisplay` (`M Y`) is NOT used here since the requested format is full month name (`F-Y`).

### 2026-08-02 — Adjustment: dropdown arrow icons (user request)
- User asked for a chevron arrow on the baseline-month `<select>` dropdowns (both add + edit forms).
- `issues_modal.blade.php` — scoped `<style>` block: `#issuesModal select.form-control, #editIssueModal select.form-control` get `appearance: none` + inline SVG chevron as a background data-URI (fill `#6b7280`, focus `#1e3a8a`), `padding-right: 34px`, `cursor: pointer`.
- Scope kept to the issue modals only (user declined broadening app-wide).

### 2026-08-02 — Adjustment: auto-select baseline month on Add Issue (user request)
- User asked the Add Issue form to auto-choose a baseline month; confirmed decisions: **date-match first** (current calendar month), fallback to the **latest month with recorded actual**, and if neither exists, the **earliest baseline**.
- `app/Services/ProjectOverviewService.php` — new `getDefaultIssueBaseline(Project)`: (1) baseline with `month_label === now()->format('Y-m')`; (2) baseline linked to the most recent progress record (`reorder('month_label','desc')->orderBy('inspection_date','desc')`, preferring `baseline_schedule_id`, falling back to a `month_label` match for legacy null-FK records); (3) earliest baseline by `planned_date`. Returns null only when the project has no baselines.
- `issues_modal.blade.php` — top `@php` block computes `$defaultBaselineId` from the service; add-form dropdown options render `{{ $baseline->id === $defaultBaselineId ? 'selected' : '' }}`. Edit-form dropdown unchanged (pre-fills from the saved issue).
- `IssueLifecycleTest` — 6 new cases (18 tests / 73 assertions for the class) exercising the service directly.
- BUG FOUND during full-suite run: `progressHistory()` relationship has a built-in `orderBy('inspection_date','asc')`, so `orderByDesc('month_label')` was only a secondary sort and the result flipped between runs (random factory inspection_dates). Fixed with `reorder('month_label','desc')` + explicit `orderBy('inspection_date','desc')`.
- Verification: `php -l` clean; full suite 251 passed / 871 assertions; `php artisan view:cache` compiles (compiled blade confirmed the `selected` expression). No controller/route changes.

### 2026-08-02 — Adjustment: manage attachments in the Edit Issue modal (user request)
- User reported the edit modal had no list of the issue's attachments and no way to add/delete them.
- FINDING: the edit form ALREADY had working "Add" file inputs (issue + resolution contexts, wired in the edit submit handler via the existing `/attachments/upload` endpoint) — but uploads failed SILENTLY (a failed upload just logged to console and the modal still closed as success). Fixed `uploadIssueAttachment()` to throw on `!data.success` and improved the add/edit catch blocks so the real message surfaces.
- Added the missing UI in `issues_modal.blade.php`:
  - New "Existing Attachments" block (`#editIssueAttachmentList`) in the edit form — chips with a clickable view span (opens the document viewer) and a red remove button.
  - `renderEditIssueAttachmentList(issue)` renders chips (icon + name + context-aware view), `deleteIssueAttachment(id)` calls the existing `DELETE /{role}/attachments/{attachment}` endpoint, then removes the chip from the list, refreshes the card list + tab, and toasts success.
  - `window._issueDataById` global maps issue id → full issue object (populated in both `displayIssues` and `displayTabIssues`) so the edit modal can show the current attachment list.
  - `editIssue()` and `openEditIssueModal()` now call `renderEditIssueAttachmentList(window._issueDataById[id])`.
  - Fixed mojibake in the add-files helper text; added `.attachment-remove-btn` CSS.
- `AttachmentPolicy::delete` confirmed admin+staff (contractors read-only) — matches the existing delete route.
- Verification: Blade compiles (`view:cache`); both inline scripts extracted and `node --check` clean (Blade `@if/@endif` normalized to `if (true) {}`); full suite 251 passed / 871 assertions. No PHP/controller changes.

---

END OF PHASE C PLAN

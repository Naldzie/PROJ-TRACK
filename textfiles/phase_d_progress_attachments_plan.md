# PHASE D — Progress Recording Attachments

CSPC PMU System — Core Project Monitoring (core_monitoring_completion_plan.md Phase D)

Status: 📋 PLANNED — not started (branch `core/progress-attachments` created from `main` @ `81c667e`; plan-only commit follows)

Branch: `core/progress-attachments` (created from `main` @ `81c667e` — Phase A/B/C all merged to main)

Date: August 4, 2026

Parent plan: textfiles/core_monitoring_completion_plan.md (Phase D, §7)
Rules: textfiles/active_reference/project_context_and_development_rules.txt (Rule 8/10/11/12)

---

## 1. OBJECTIVE

Let each monthly progress record (inspection) carry **evidence files** — inspection photos / PDFs —
so the Progress Recording History and the monthly baseline cards show real, database-backed
attachments with preview (via `document-viewer-modal`).

- Attach files to a progress record with `context: 'inspection'` (single context — NO issue vs
  resolution grouping; progress attachments are always inspection evidence).
- Follow the **Phase C attachment-management UX** on the issue modals (selected-files preview,
  attachment chips, deferred edit deletion via the reusable delete-confirmation modal) minus the
  context grouping labels.
- Mirror the **Phase C upload approach**: the record is saved via its existing JSON endpoint first,
  then each chosen file is uploaded through the existing `/attachments/upload` endpoint
  (`attachable_type = App\Models\ProjectProgressHistory`, `context = 'inspection'`). The record
  endpoints stay JSON — no FormData conversion, no duplicated file-handling logic in
  `ProjectProgressController`.
- Clean up a record's attachments (rows + physical files) when the record is deleted via
  `destroyProgress()` (mirrors `IssueController::destroy`).

Out of scope: `record_monthly_progress_modal.blade.php` is dead code (never included on any page —
only a commented-out button in `progress.blade.php` references it) and is left untouched.

---

## 2. CURRENT STATE & PATTERNS TO MIRROR (verified 2026-08-04)

Codebase study completed. Everything below was verified on disk.

| Concern | Reference | Pattern |
|---|---|---|
| Model | `app/Models/ProjectProgressHistory.php` | `HasFactory`, `$fillable`, `$casts` (dates, decimals, json `original_values`, array `activity_id`); `baselineSchedule()` belongsTo; `recordedBy()` belongsTo; **NO `attachments()` morphMany yet** |
| Attachments | `app/Models/Attachment.php` | `CONTEXTS = ['inspection','issue','resolution']`; `ALLOWED_ATTACHABLE_TYPES` already includes `ProjectProgressHistory::class` + `Issue::class`; `project_id` denormalized; accessors `file_url`/`display_size`/`size_kb`; `booted()` deleting hook removes the physical file |
| Upload endpoint | `app/Http/Controllers/AttachmentController.php` | `upload()` validates `project_id`/`attachable_type`/`attachable_id`/`context`/`file` (mimes jpg,jpeg,png,pdf; max 20480 KB); guards attachable belongs to project (422); policy 403 (admin+staff); ActivityLog with `project_id`; returns `attachment` JSON |
| Policy | `app/Policies/AttachmentPolicy.php` | `view` delegates to project view; `create`/`delete` admin+staff; contractors read-only |
| Routes | `routes/web.php` | staff 224–234, contractor 311–315, admin 516–526 — `/{role}/attachments/upload` (POST), `/{role}/attachments/{attachment}` (DELETE), `/{role}/attachments/{attachment}/view|download` (GET) |
| Progress controller | `app/Http/Controllers/ProjectProgressController.php` | `recordMonthlyProgress()` (POST, JSON) returns `progress_record`; `editProgressRecord()` (PUT, JSON) returns `record` fresh; `destroyProgress()` admin-only, latest-record-only, inside a transaction — **no attachment cleanup yet** |
| Record modal (ACTIVE) | `resources/views/projects/project_detail/components/modals/record_progress_modal.blade.php` | id `recordProgressModal`; form `recordProgressForm`; included at `baseline-schedule.blade.php:514`; JS `baseline-schedule-record-progress-modal.js` (`saveMonthlyProgress()`) posts JSON to `buildRecordProgressEndpoint()`, then reloads (or carry-over modal) after ~2s |
| Edit modal (ACTIVE) | `resources/views/projects/project_detail/components/modals/_edit_progress_modal.blade.php` | id `editProgressModal`; form `editProgressForm`; JS `baseline-schedule-edit-progress-modal.js` (`openEditProgressModal(recordId, monthLabel, isLastMonth)` reads `window._progressRecordData[recordId]`; `saveProgressEdit()` PUTs JSON then reloads after ~2s; `deleteProgressRecord()` already uses the reusable `showDeleteConfirmation`) |
| Month cards | `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php` | server-rendered `@forelse` over baselines; `$latestProgress = $plan->progressHistory()->...->first()`; Record/Edit buttons call `openRecordProgressModal(...)` / `openEditProgressModal(...)` |
| History table | `resources/views/projects/project_detail/components/tabs/progress.blade.php` | server-rendered table from `$progressHistoryRecords` (sorted); edit button + admin delete button per row; `window._progressRecordData = @json($project->progressHistory->load('recordedBy')->each->setAppends(['recorded_by_name','activity_names'])->keyBy('id'))` at line 621 (also defined at `baseline-schedule.blade.php:11`) |
| Document viewer | `resources/views/components/document-viewer-modal.blade.php` | `window.openDocumentViewer({ id, name, type, size, uploadedBy, uploadedAt, viewUrl, downloadUrl })`; included once on the page via `documents.blade.php:167` (all tabs render together on `project_detail.blade.php`) — NOT re-included on progress/issues tabs (Phase C confirmed this; avoid duplicate element id) |
| Delete confirmation | `resources/views/components/delete-confirmation-modal.blade.php` | global `showDeleteConfirmation(title, message, onConfirm)` + `confirmDeleteAction()`; included at `baseline-schedule.blade.php:501` — available to both progress modals |
| Phase C pattern | `public/js/admin/projects/issues-modal.js` + `issues_modal.blade.php` + `issues-modal.css` | pending-files preview (`_pendingIssueFiles`), `addPendingFiles`/`renderPendingFiles`/`removePendingFile`/`clearPendingFiles`/`collectPendingFiles`; chips `renderAttachmentChips`/`getAttachmentIcon`/`openIssueAttachmentById`; edit list `renderEditIssueAttachmentList`; deferred delete `_pendingDeleteAttachmentIds`/`queueAttachmentForDeletion`/`flushPendingAttachmentDeletions` (no mutation of the shared cache); upload via `/{role}/attachments/upload` after the record save |
| Phase C chips CSS | `public/css/admin/projects/issues-modal.css` | global `.attachment-chip` (clickable), `.attachment-chip.edit-chip` (remove ✕ always visible), `.attachment-remove-btn`, `.att-name` ellipsis — already on the page and reused here |

### Verified unknowns discovered during study

1. **Active record modal** — `record_monthly_progress_modal.blade.php` (id `recordMonthlyProgressModal`,
   the modal named in the parent plan §7) is NOT included anywhere; the ACTIVE record modal is
   `record_progress_modal.blade.php` (id `recordProgressModal`). Phase D targets the ACTIVE one and
   leaves the dead modal untouched (confirmed with user).
2. **`window._progressRecordData` is defined twice** — `baseline-schedule.blade.php:11` AND
   `progress.blade.php:621` (both `@json($project->progressHistory->load('recordedBy')->each->setAppends([...])->keyBy('id'))`).
   Both must gain `->load('attachments')` so the edit modal can render the existing attachment list
   from either tab. `load()` is idempotent on the shared eager-loaded collection.
3. **Raw JSON vs mapped JSON** — `getIssues()` maps attachments explicitly (id, original_name, context,
   file_url, display_size, uploaded_by, created_at). `@json` serialization does NOT include the
   `file_url`/`display_size` accessors (no `$appends` on `Attachment`). For progress, chips only need
   `id` + `original_name` (+ optionally `file_size`); view/download URLs are derived from `id` +
   `window.userRole` (`/{role}/attachments/{id}/view|download`). No `$appends` change needed.
4. **Server-rendered chips (month cards + history table)** — blade has the full model (`$att->file_url`,
   `$att->display_size`, `$att->uploaded_by`, `$att->created_at`) and the role from
   `auth()->user()->role`, so `window.openDocumentViewer(...)` can be wired inline without extra JSON.

---

## 3. CONFIRMED DECISIONS

1. **Upload mechanism** — Phase C separate-endpoint pattern. Record/edit endpoints stay JSON; after
   the record save succeeds, the client uploads each pending file via the existing
   `/attachments/upload` endpoint (`context: 'inspection'`). No FormData conversion of the record
   forms, no file-handling duplication in `ProjectProgressController`.
2. **Single context** — `inspection` only. No issue/resolution grouping labels anywhere.
3. **Model** — `ProjectProgressHistory` gains `attachments()` morphMany (inverse side; the Attachment
   model already allows the type).
4. **Edit-modal deletion** — deferred like Phase C: queued in `window._pendingDeleteAttachmentIds`,
   confirmed via the reusable `showDeleteConfirmation`, and flushed to the server only after a
   successful Update. Cancelling/closing the modal keeps attachments intact; the shared
   `window._progressRecordData` cache is never mutated (render-time filter only).
5. **destroyProgress() cleanup** — explicitly delete the record's attachment rows before
   `$record->delete()` so physical files are removed via the `Attachment` deleting hook (the morph
   pair has no FK cascade). Skipped when the record has no attachments.
6. **Dead-code modal** — `record_monthly_progress_modal.blade.php` left untouched (out of scope).
7. **Dedicated assets** — new `public/js/admin/projects/progress-attachments.js` (shared by both
   record/edit flows, mirroring `issues-modal.js`). Reuse the existing global `.attachment-chip` /
   `.edit-chip` / `.attachment-remove-btn` CSS (already loaded on the page via `issues-modal.css`);
   no new CSS file.
8. **Chips** — server-rendered for the history table + month cards; JS-rendered in the record/edit
   modals. All open `window.openDocumentViewer` with role-scoped attachment view/download URLs.

---

## 4. IMPLEMENTATION STEPS (ordered; test each before the next — Rule 8)

### Step 1 — Model

MODIFIED: `app/Models/ProjectProgressHistory.php`

- Add relationship:
  - `attachments()` → `morphMany(\App\Models\Attachment::class, 'attachable')`
- No `$fillable`/`$casts` changes (attachment rows are created by `AttachmentController`, not this model).

Verify: `php -l`; a quick `php artisan tinker` snippet that a factory progress record exposes
`->attachments` as an empty collection.

### Step 2 — Controller

MODIFIED: `app/Http/Controllers/ProjectProgressController.php`

- `recordMonthlyProgress()` — add `'attachments' => $progressRecord->attachments->map(fn($a) => $a->id)` (or
  `[]`) to the success JSON (payload consistency; the page reloads anyway).
- `editProgressRecord()` — add `'attachments' => []` (or the record's attachment ids) to the success JSON.
- `destroyProgress()` — inside the existing transaction, BEFORE `$record->delete()`:
  - `Attachment::where('attachable_type', ProjectProgressHistory::class)->where('attachable_id', $record->id)->get()->each->delete();`
  - (Deleting the model rows triggers the `booted()` hook → physical files removed.)
- Import `App\Models\Attachment` + `App\Models\ProjectProgressHistory` (already imported? verify).

Verify: `php -l`; `php artisan test --filter=ProgressAttachmentTest` after Step 7; full suite at the end.

### Step 3 — Modal data (edit modal needs existing attachments)

MODIFIED (both): `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php` (line 11)
and `resources/views/projects/project_detail/components/tabs/progress.blade.php` (line 621)

- `window._progressRecordData = @json($project->progressHistory->load('recordedBy')->load('attachments')->each->setAppends(['recorded_by_name', 'activity_names'])->keyBy('id'));`

Verify: `php artisan view:cache` compiles; blade JSON includes an `attachments` key per record.

### Step 4 — New JS: `public/js/admin/projects/progress-attachments.js`

NEW file (mirrors `issues-modal.js`; shared by record + edit flows). Includes:

- `getAttachmentIcon(filename)` — same map as issues (pdf/image/text icons).
- `renderAttachmentChips(attachments)` — clickable `.attachment-chip` chips; stores each att on
  `window._progressAttachments[id]`; `openProgressAttachmentById(id)` → `window.openDocumentViewer`
  with `viewUrl: '/' + window.userRole + '/attachments/' + id + '/view'`, `downloadUrl: ...'/download'`,
  `type: 'Inspection Evidence'`, `size` from `display_size || ''`, `uploadedBy`/`uploadedAt` from raw fields.
- Pending-files system (single context, two inputs: `progressAttachmentFiles`, `editProgressAttachmentFiles`):
  `window._pendingProgressFiles`, `getPendingFiles`, `addPendingFiles` (append + dedupe by name+size,
  object URL for images, clear input), `removePendingFile`, `clearPendingFiles`, `renderPendingFiles`
  (thumbnail for images, `.attachment-chip edit-chip` + remove), `collectPendingFiles`.
- `uploadProgressAttachment(recordId, file)` — POST `/${window.userRole}/attachments/upload` FormData
  (`project_id`, `attachable_type=App\Models\ProjectProgressHistory`, `attachable_id`, `context='inspection'`,
  `file`); throws on `!data.success`; returns the `attachment` payload.
- `uploadProgressFiles(recordId, files)` — `Promise.all` over the pending files.
- Edit list: `renderEditProgressAttachmentList(record)` — chips with view span + remove button,
  EXCLUDING ids in `window._pendingDeleteAttachmentIds` (never mutate `record.attachments`).
- Deferred delete: `window._pendingDeleteAttachmentIds = []`; `deleteProgressAttachment(attachmentId)` →
  `showDeleteConfirmation('Remove Attachment', ..., queueProgressAttachmentForDeletion)`;
  `queueProgressAttachmentForDeletion(id)` (push + re-render + drop from `_progressAttachments`);
  `flushPendingAttachmentDeletions()` → DELETE `/${role}/attachments/{id}` per queued id, clears queue,
  Promise.all. Called only after a successful record update.
- `escapeHtml`, `showSuccessMessage`, `showErrorMessage` fallbacks (same as issues-modal.js).

Wiring (to be added to the two existing modal JS files or loaded before them):
- Record flow (`baseline-schedule-record-progress-modal.js` `saveMonthlyProgress()`): after
  `data.success`, collect pending files → `uploadProgressFiles(data.progress_record.id, files)` →
  clear pending → existing toast + reload/carry-over flow. On upload failure, surface the real
  message (Phase C fix pattern) but still reload (record already saved).
- Edit flow (`baseline-schedule-edit-progress-modal.js`): `openEditProgressModal()` clears
  `_pendingDeleteAttachmentIds` + pending lists and calls `renderEditProgressAttachmentList(record)`;
  `saveProgressEdit()` after `data.success` → `uploadProgressFiles(recordId, files)` →
  `flushPendingAttachmentDeletions()` → clear pending → existing toast + reload.
- `change` listeners on both file inputs → `addPendingFiles`.
- `<script src=".../progress-attachments.js">` loaded in both `baseline-schedule.blade.php` and
  `progress.blade.php` (or a single include point on `project_detail.blade.php`).

Verify: extract inline scripts + `node --check` on the new JS; `php artisan view:cache` compiles.

### Step 5 — Modals (file inputs + previews)

MODIFIED: `resources/views/projects/project_detail/components/modals/record_progress_modal.blade.php`

- New "Inspection Evidence" field (label `Inspection Evidence Files`, optional): single
  `<input type="file" id="progressAttachmentFiles" name="progress_attachment_files[]" multiple accept="image/*,.pdf">`
  + helper text (jpg, png, pdf — max 20 MB each) + pending preview container
  `#progressAttachmentFilesPreview` (styled like the issues modal: `d-flex flex-wrap gap-1`).
- No resolution section, no context labels.

MODIFIED: `resources/views/projects/project_detail/components/modals/_edit_progress_modal.blade.php`

- "Add Inspection Evidence Files" input `#editProgressAttachmentFiles` + preview
  `#editProgressAttachmentFilesPreview`.
- "Existing Attachments" block `#editEditProgressAttachmentList` (rendered by JS; chips with view +
  remove).

Verify: `php artisan view:cache`; browser (on request): pick → preview appears; save → chips after reload.

### Step 6 — Views (chips on history table + month cards)

MODIFIED: `resources/views/projects/project_detail/components/tabs/progress.blade.php`

- History table: new **Attachments** column header + cell rendering the record's attachment chips
  server-side (reuse `.attachment-chip`):
  - Loop `$history->attachments` → chip span with icon + name, `onclick="window.openDocumentViewer({...})"`
    using `auth()->user()->role` for view/download URLs and `$att->file_url`/`display_size`/`uploaded_by`.
  - When empty, render `—` / muted placeholder.
- Ensure `$progressHistoryRecords` has attachments loaded (add `->load('attachments')` where the
  collection is built, or rely on Step 3's eager load).

MODIFIED: `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`

- Month cards: for the latest record, show a compact paperclip count badge in the card header
  (e.g. `📎 N`) + attachment chips rendered inline under the Actual indicator (or in the collapsible
  activities section — adjustable) via the same server-side chip markup.
- Eager-load attachments on `$latestProgress` (`$plan->progressHistory()->with('attachments')->...`).

Verify: `php artisan view:cache`; browser check of both tabs.

### Step 7 — Tests

NEW: `tests/Feature/ProgressAttachmentTest.php` (`RefreshDatabase`; `Storage::fake('public')`;
`User::create([...])` per environment finding; direct controller invocation; `UploadedFile::fake()->createWithContent()`
with real JPEG magic bytes — GD not installed).

Cases:
1. `test_record_progress_then_upload_binds_to_new_record` — `recordMonthlyProgress()` succeeds
   (returns `progress_record.id`), then `AttachmentController::upload()` with
   `attachable_type=ProjectProgressHistory`, `attachable_id=<id>`, `context=inspection` → row + file
   exist; `$record->attachments` returns it.
2. `test_edit_progress_accepts_additional_attachments` — existing record + attachment; a second
   upload binds to the same record; both returned.
3. `test_deferred_delete_flush_removes_attachment` — `AttachmentController::delete()` removes row +
   physical file (this is the server side of the flush).
4. `test_destroy_progress_cleans_attachments` — record with attachments deleted via
   `destroyProgress()` → attachment rows gone + physical files gone.
5. `test_record_response_exposes_attachments` — `recordMonthlyProgress()` JSON contains
   `attachments` key (empty array for a record with none).
6. `test_contractor_cannot_upload_progress_attachment` — 403 (policy regression guard).
7. `test_attachment_chip_data_present_in_progress_json` — `window._progressRecordData` serialization
   source (controller/service) includes `attachments`; assert the record model with `load('attachments')`
   serializes an `attachments` key.

Existing `AttachmentTest` + `IssueLifecycleTest` + `OverviewTest` + `ActivityVisibilityTest` must stay
green (full-suite baseline on main: 251 passed / 871 assertions — re-verify).

### Step 8 — Verification

- `php -l` on every changed PHP file.
- `php artisan test --filter=ProgressAttachmentTest` — all green.
- Full suite `php artisan test` — no regressions (baseline 251 / 871).
- `php artisan route:list` — attachment + progress routes unchanged.
- `php artisan view:cache` — all Blade templates compile.
- Browser check (manual, on request): record progress with files → chips on month card + history;
  edit → existing list + remove (deferred) + add files; delete record → attachments gone.

---

## 5. FILES TOUCHED

NEW:
- `textfiles/phase_d_progress_attachments_plan.md` (this file)
- `public/js/admin/projects/progress-attachments.js`
- `tests/Feature/ProgressAttachmentTest.php`

MODIFIED:
- `app/Models/ProjectProgressHistory.php` (add `attachments()` morphMany)
- `app/Http/Controllers/ProjectProgressController.php` (`attachments` in responses; destroy cleanup)
- `resources/views/projects/project_detail/components/modals/record_progress_modal.blade.php`
  (Inspection Evidence input + pending preview)
- `resources/views/projects/project_detail/components/modals/_edit_progress_modal.blade.php`
  (Add Evidence input + preview + Existing Attachments list)
- `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php`
  (month-card chips; `_progressRecordData` `->load('attachments')`; load new JS)
- `resources/views/projects/project_detail/components/tabs/progress.blade.php`
  (history-table Attachments column; `_progressRecordData` `->load('attachments')`; load new JS)
- `public/js/admin/projects/baseline-schedule-record-progress-modal.js` (upload pending files on save)
- `public/js/admin/projects/baseline-schedule-edit-progress-modal.js` (open resets + render list; save uploads + flushes)

NOT touched:
- `app/Models/Attachment.php`, `app/Http/Controllers/AttachmentController.php`, `app/Policies/AttachmentPolicy.php`,
  `routes/web.php` (infrastructure already Phase-A ready)
- `record_monthly_progress_modal.blade.php` (dead code — out of scope)
- `public/css/admin/projects/issues-modal.css` (chip classes reused as-is)

---

## 6. OPEN QUESTIONS / STILL TO RESOLVE

1. **Month-card chip placement** — header paperclip count badge vs chips inline under Actual vs chips
   in the collapsible activities section. Default: paperclip count badge in the header + chips under
   the Actual indicator. Confirm at implementation.
2. **History-table chip density** — full chips in the Attachments column vs a compact paperclip
   count with preview on click. Default: full chips (mirrors issues tab). Adjustable.
3. **Upload failure after record save** — record already saved, one file fails. Default (Phase C
   pattern): surface the real message, keep the record, let the user re-add the failed file.
   Confirm acceptable.
4. **New JS load location** — load `progress-attachments.js` once on `project_detail.blade.php` vs
   in both `baseline-schedule.blade.php` and `progress.blade.php`. Default: include alongside the
   existing per-tab scripts (both tabs) to keep include order explicit.

---

## 7. PROCESS NOTES

- Rule 12: work only on `core/progress-attachments`; do NOT merge to main without explicit user approval.
- Rule 8: test after each step (Step 1 → 8 in order).
- Rule 10: inform the user after the phase completes.
- Rule 11: log progress in §8 as each step lands.
- Plan-only commit BEFORE implementation (established pattern: Phase A/B/C plans were committed before any code).

---

## 8. SESSION / CHANGES LOG

(Chronological log of what is built and any bugs found — append as the phase is implemented.)

### 2026-08-04 — Plan created
- Branch `core/progress-attachments` created from `main` @ `81c667e` (Phase A/B/C merged).
- Codebase study complete (models, controllers, views, routes, policies verified).
- Plan written (this file); committed as plan-only (no implementation); pushed to GitHub.

### 2026-08-04 — Phase implemented (Rule 8: tested per step)
- **Step 1 (Model):** `ProjectProgressHistory::attachments()` morphMany appended (mirrors `Issue::attachments`).
- **Step 2 (Controller):** `ProjectProgressController` — `attachments` key in `recordMonthlyProgress()` + `editProgressRecord()` success JSON; explicit attachment-row cleanup (row → physical file via model hook) inside `destroyProgress()` before `$record->delete()`.
- **Step 3 (Data load):** `baseline-schedule.blade.php` + `progress.blade.php` eager-load `attachments` on the `_progressRecordData` payloads.
- **Step 4 (JS):** new `public/js/admin/projects/progress-attachments.js` — shared helpers (chips, document-viewer open, pending-files preview system, `uploadProgressFiles` via POST `/{role}/attachments/upload` with `attachable_type=App\Models\ProjectProgressHistory`, `context=inspection`, deferred delete queue mirroring Phase C `showDeleteConfirmation`). Wired into `baseline-schedule-record-progress-modal.js` (upload after successful save) and `baseline-schedule-edit-progress-modal.js` (reset/render, upload + flush on successful update; never mutates `_progressRecordData` cache).
- **Step 5 (Views):** `record_progress_modal.blade.php` — "Inspection Evidence Files" input + preview; `_edit_progress_modal.blade.php` — add-evidence input + preview + "Existing Attachments" list with deferred delete.
- **Step 6 (Views):** `progress.blade.php` history table — Attachments column with per-row chips (inline `openDocumentViewer`, role-scoped URLs, pdf/image icon); `baseline-schedule.blade.php` month cards — Evidence chips row under indicators for the latest record (attachments eager-loaded via `$latestProgress` query).
- **Step 7 (Tests):** new `tests/Feature/ProgressAttachmentTest.php` (9 tests / 54 assertions) — mirrors AttachmentTest conventions (direct controller invocation, `Storage::fake('public')`, real JPEG magic bytes via `createWithContent`). Covers: record response `attachments` key, upload binds to new record, edit exposes attachment ids, morphMany loads attachments, delete removes row+file, contractor upload/delete 403s, destroyProgress cleans attachments + files, contractor cannot destroy.
- **Step 8 (Verification):** `php -l` clean on changed PHP; `php artisan view:cache` OK; full suite **260 passed / 925 assertions** (baseline 251/871 + 9/54 new; zero regressions).

### 2026-08-04 — Awaiting user approval to merge
- All work on `core/progress-attachments`; NOT merged to `main` (Rule 12 — requires explicit approval).

---

END OF PHASE D PLAN

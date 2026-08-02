# PHASE A — Attachments Infrastructure

CSPC PMU System — Core Project Monitoring (core_monitoring_completion_plan.md Phase A)

Status: ✅ IMPLEMENTED — all steps complete, tests green (2026-08-02)

Branch: `core/attachments-infra` (created from `main` @ `7d27678`)

Date: August 2, 2026

Parent plan: textfiles/core_monitoring_completion_plan.md (Phase A, §4)
Rules: textfiles/active_reference/project_context_and_development_rules.txt (Rule 8/10/11/12)

---

## 1. OBJECTIVE

Build the foundation that Phases C (issue lifecycle) and D (progress recording)
will use: a generic, polymorphic **`attachments`** table plus its model,
controller, policy, routes, and tests. Phase A delivers ONLY the infrastructure
— no wiring into issues or progress records yet (that happens in C/D).

Key design principle (parent plan decision 2): do NOT reuse the `documents`
table. `documents` is a formal, type-categorized project library (required
`document_type_id`, `onDelete('restrict')`). Attachments are record-level
evidence files (inspection photo, issue photo, resolution proof) — a different
concept that needs a morph table.

---

## 2. CURRENT STATE & PATTERNS TO MIRROR (verified 2026-08-02)

The existing Document module is the reference implementation. Every pattern below
is copied from it so the new module is consistent with the codebase.

| Concern | Reference | Pattern |
|---|---|---|
| Model | `app/Models/Document.php` | `HasFactory`, `$fillable`, accessors (`file_url`, `full_path`, `file_size` KB, `file_extension`, `fileExists()`), `booted()` deleting hook removes physical file, `generateStoragePath()` static |
| Controller | `app/Http/Controllers/DocumentController.php` | `upload()` (validate → policy check → `storeAs(..., 'public')` → create → JSON), `delete()` (policy → capture name/project → `delete()` → ActivityLog), `view()` (`Storage::disk('public')->response($path, $name, ['Content-Disposition'=>'inline...'])`), `download()` (`->download($path, $name)`) |
| Policy | `app/Policies/DocumentPolicy.php` | `view` delegates to project view; `create`/`delete` admin+staff only; contractors read-only |
| Routes | `routes/web.php` DOCUMENT ROUTES blocks | staff group: upload/delete/view/download; contractor group: view/download only; admin group: upload/delete/view/download. Groups use `Route::prefix('staff')->middleware(['auth','active','role:staff'])` |
| Migration | `database/migrations/2026_05_25_100001_create_documents_table.php` | `Schema::create`, `$table->id()`, foreignId/unsignedBigInteger + explicit FK with `onDelete('cascade')`, explicit `->index()`, `timestamps()` |
| Feature test | `tests/Feature/Services/AI/ProgressDeleteGuardTest.php` | `RefreshDatabase`, `setUp()` with `$this->actingAs(User::create([...]))`, direct controller invocation via `app(Controller::class)->method(...)`, assert on JSON `getData()` |
| Storage | `config/filesystems.php` disk `public` | `storage/app/public`, URL `APP_URL/storage/...`; path root `projects/...` |

Storage path convention (from `Document::generateStoragePath()`): use
`$project->code` in the path. Attachments will use:
`projects/attachments/{PROJECT_CODE}/{context}/{Y-m-d-His}-{uniqid}.{ext}`.

Test DB: sqlite `:memory:` (phpunit.xml), `RefreshDatabase` migrates automatically.
File-upload tests use Laravel's `UploadedFile::fake()->image()/->create()`.

Morph targets (Phase A allows, Phases C/D wire the inverse relation):
- `App\Models\ProjectProgressHistory` (project_progress_history)
- `App\Models\Issue` (issues)

Both models exist today; neither has an `attachments()` relation yet (deferred to
C/D — see decisions).

---

## 3. CONFIRMED DECISIONS

1. **New `attachments` table** — polymorphic, NOT the `documents` table.
2. **Allowed file types** — `jpg, jpeg, png, pdf`, max 20 MB (20480 KB).
3. **Context values** — `inspection` | `issue` | `resolution`.
4. **Allowed attachable types** — `ProjectProgressHistory::class`, `Issue::class`.
5. **`file_size` stored as a column** (bytes) at upload time — avoids a
   `Storage::size()` round-trip per row (Document recomputes via accessor; we
   store instead because attachments render in lists/thumbnails).
6. **Inverse `morphMany` relations** NOT added in Phase A — kept infra-only;
   added in Phase C (Issue) and Phase D (ProjectProgressHistory).
7. **Policy** — `AttachmentPolicy` mirroring `DocumentPolicy`; auto-discovery by
   convention (verify in Step 3).
8. **ActivityLog** on upload/delete (mirrors DocumentController) — note: the
   `project_id` population on activity_logs is Phase B's job, not this phase.

---

## 4. IMPLEMENTATION STEPS (ordered; test each before the next — Rule 8)

### Step 1 — Migration

NEW: `database/migrations/<timestamp>_create_attachments_table.php`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | `$table->id()` |
| `attachable_type` | string(255) | not null |
| `attachable_id` | bigint unsigned | not null |
| `project_id` | foreignId → projects | `onDelete('cascade')`, indexed |
| `context` | string(20) | nullable — `inspection|issue|resolution` |
| `original_name` | string(255) | original client filename |
| `file_path` | text | relative: `projects/attachments/...` |
| `file_size` | bigint unsigned | nullable — bytes |
| `mime_type` | string(100) | nullable |
| `uploaded_by` | string(255) | nullable — user name/email |
| `created_at` / `updated_at` | timestamps | |

Indexes: `['attachable_type', 'attachable_id']` (morph lookup),
`['project_id']` (project cascade/scope).

`down()` drops the table (physical files are not the migration's concern; the
model's deleting hook handles orphans during normal deletes).

### Step 2 — Model

NEW: `app/Models/Attachment.php`

- `use HasFactory`.
- Constants:
  - `CONTEXTS = ['inspection', 'issue', 'resolution']`
  - `ALLOWED_ATTACHABLE_TYPES = [ProjectProgressHistory::class, Issue::class]`
- `$fillable`: `project_id, attachable_type, attachable_id, context,
  original_name, file_path, file_size, mime_type, uploaded_by`.
- Relationships:
  - `project()` → `belongsTo(Project::class)`
  - `attachable()` → `morphTo()` (returns the progress record / issue)
- Accessors:
  - `file_url` → `url('storage/' . $this->file_path)`
  - `full_path` → `storage_path('app/public/' . $this->file_path)`
  - `file_extension` → `pathinfo($this->file_path, PATHINFO_EXTENSION)`
  - `size_kb` → `round($this->file_size / 1024, 2)` (guard null → 0)
  - `display_size` → human string: `"X.X KB"` if < 1 MB else `"X.X MB"`
  - `fileExists()` → `Storage::disk('public')->exists($this->file_path)`
- `booted()` deleting hook — delete physical file if it exists (copy the
  `Document::booted()` body exactly).
- NOTE: do NOT define an accessor named `file_size` (it is a real column here,
  unlike Document).

### Step 3 — Policy

NEW: `app/Policies/AttachmentPolicy.php`

- `view(User, Attachment)` → `$user->can('view', $attachment->project)`
- `create(User, Project)` → admin/staff role
- `delete(User, Attachment)` → admin/staff role

Verify auto-discovery: Laravel 11 resolves `AttachmentPolicy` for `Attachment`
by convention (like `DocumentPolicy`). Confirm with a quick test/`php artisan
list` or by checking `Gate` resolution; if not auto-discovered, register
explicitly in `app/Providers/AppServiceProvider.php` `boot()`.

### Step 4 — Controller

NEW: `app/Http/Controllers/AttachmentController.php`

- `upload(Request)`:
  - Validate:
    - `project_id` → `required|integer|exists:projects,id`
    - `attachable_type` → `required|string|in:` . implode(',', ALLOWED types)
    - `attachable_id` → `required|integer`
    - `context` → `required|string|in:inspection,issue,resolution`
    - `file` → `required|file|max:20480|mimes:jpg,jpeg,png,pdf`
  - Load `$project`; policy check `Auth::user()->can('create', [Attachment::class, $project])` → 403 JSON on failure.
  - Load the attachable (`$attachableType::findOrFail($validated['attachable_id'])`); verify `$attachable->project_id === $project->id` else 422 JSON.
  - Generate path via a static `Attachment::generateStoragePath($project->code, $context, $ext)` (mirror `Document::generateStoragePath`).
  - `$file->storeAs(dirname($path), basename($path), 'public')`.
  - `Attachment::create([... project_id, attachable_type, attachable_id, context, original_name => $file->getClientOriginalName(), file_path, file_size => $file->getSize(), mime_type => $file->getClientMimeType(), uploaded_by => Auth::user()->name ?? Auth::user()->email])`.
  - `ActivityLog::create([... 'action' => 'Upload Attachment', 'description' => "... for project \"{$project->title}\" ..."])` (no project_id yet — Phase B).
  - Return JSON `{ success, message, attachment: { id, original_name, file_url, size_kb, display_size, uploaded_by, created_at, context } }`.
  - Wrap in try/catch → 500 JSON (copy DocumentController pattern).
- `delete(Attachment $attachment)`:
  - Policy check `delete` → 403 JSON.
  - Capture name/project before delete; `$attachment->delete()` (hook removes file).
  - `ActivityLog::create` ('Delete Attachment'); JSON success.
- `view(Attachment $attachment)`:
  - Policy check `view` → abort 403.
  - `fileExists()` else abort 404.
  - `Storage::disk('public')->response($attachment->file_path, $attachment->original_name, ['Content-Disposition' => 'inline; filename="..."]')`.
- `download(Attachment $attachment)`:
  - Policy check `view`; `fileExists()`; `Storage::disk('public')->download($attachment->file_path, $attachment->original_name)`.

### Step 5 — Routes

MODIFIED: `routes/web.php` — add ATTACHMENT ROUTES blocks mirroring the DOCUMENT
ROUTES blocks:

- Staff group (after document routes):
  - `POST /attachments/upload` → `AttachmentController@upload` → `staff.attachments.upload`
  - `DELETE /attachments/{attachment}` → `delete` → `staff.attachments.destroy`
  - `GET /attachments/{attachment}/view` → `view` → `staff.attachments.view`
  - `GET /attachments/{attachment}/download` → `download` → `staff.attachments.download`
- Contractor group (read-only):
  - `GET /attachments/{attachment}/view` → `contractor.attachments.view`
  - `GET /attachments/{attachment}/download` → `contractor.attachments.download`
- Admin group (same as staff):
  - `admin.attachments.upload` / `.destroy` / `.view` / `.download`

### Step 6 — Tests

NEW: `tests/Feature/AttachmentTest.php` (RefreshDatabase; actingAs admin in
`setUp`, override per-test for staff/contractor). Use `Project::factory()`
(`['status' => 'Ongoing']`), direct `ProjectProgressHistory::create([...])` and
`Issue::create([...])` attachables, and `UploadedFile::fake()->image()` /
`->create('file.pdf', 100, 'application/pdf')` /
`->create('file.docx', 100, 'application/vnd.openxmlformats-...')`.

Cases:
1. `test_admin_uploads_image_attachment` — success; DB row has project_id,
   attachable linkage, context; `Storage::disk('public')->exists(path)` true;
   response has `file_url`.
2. `test_upload_rejects_non_allowed_file_type` — docx → validation error (422).
3. `test_upload_rejects_unknown_attachable_type` — 422.
4. `test_upload_rejects_attachable_from_another_project` — 422.
5. `test_contractor_cannot_upload` — 403.
6. `test_delete_removes_row_and_physical_file` — row gone; `Storage::exists`
   false.
7. `test_view_streams_file_inline` — 200, `Content-Type` image/pdf.
8. `test_download_forces_attachment` — 200, `Content-Disposition` attachment.
9. `test_model_accessors` — `file_url`, `size_kb`, `display_size`,
   `file_extension`, `fileExists`.
10. `test_morph_attachable_resolves` — attach to a `ProjectProgressHistory` and
    an `Issue`; `$attachment->attachable` returns the correct model.

### Step 7 — Verification

- `php -l` on every new file.
- `php artisan test --filter=AttachmentTest` — all green.
- Full suite `php artisan test` — no regressions (baseline: 198 passed / 692
  assertions on this branch's base).
- Confirm route list (`php artisan route:list` grep attachments) and policy
  resolution.

---

## 5. FILES TOUCHED

NEW:
- `database/migrations/<timestamp>_create_attachments_table.php`
- `app/Models/Attachment.php`
- `app/Policies/AttachmentPolicy.php`
- `app/Http/Controllers/AttachmentController.php`
- `tests/Feature/AttachmentTest.php`

MODIFIED:
- `routes/web.php` (attachment route blocks in staff/contractor/admin groups)
- (possible) `app/Providers/AppServiceProvider.php` — ONLY if policy
  auto-discovery fails

---

## 6. OPEN QUESTIONS / STILL TO RESOLVE

1. ~~Policy auto-discovery~~ — CONFIRMED (AttachmentTest contractor 403 proves `AttachmentPolicy` resolves by convention; no explicit registration needed).
2. `uploaded_by` stores user name (mirrors Document) — used as planned (name, falling back to email); no change.
3. Whether `context` should also be enforced against attachable type (e.g. `resolution` only valid on Issue) — Phase A allows all combos; a cross-field rule can be added later if needed. (Open for Phase C/D.)

---

## 7. PROCESS NOTES

- Rule 12: work only on `core/attachments-infra`; do NOT merge to main without
  explicit user approval.
- Rule 8: test after each step (Step 1 → 7 in order).
- Rule 10: inform the user after the phase completes.
- Rule 11: log progress in §8 as each step lands.

---

## 8. SESSION / CHANGES LOG

(Chronological log of what is built and any bugs found — append as the phase is
implemented.)

### 2026-08-02 — Plan created
- Branch `core/attachments-infra` created from main @ 7d27678.
- Plan written (this file); committed as plan-only (no implementation).

### 2026-08-02 — Phase implemented (user approved "yes please")
- Step 1: Migration `database/migrations/2026_08_02_000001_create_attachments_table.php` — schema exactly as planned (morph pair, project_id FK cascade + index, context string(20), file metadata, composite morph index).
- Step 2: `app/Models/Attachment.php` — CONTEXTS + ALLOWED_ATTACHABLE_TYPES constants, project()/attachable() morphTo, accessors (file_url, full_path, size_kb, display_size, file_extension, fileExists()), booted() deleting hook removes the physical file, generateStoragePath() static. No file_size accessor (real column).
- Step 3: `app/Policies/AttachmentPolicy.php` — view→delegates to project view; create/delete→admin+staff. Auto-discovery CONFIRMED via AttachmentTest (contractor 403 from `Gate::can` on policy methods).
- Step 4: `app/Http/Controllers/AttachmentController.php` — upload() (validate incl. max:20480 + mimes:jpg,jpeg,png,pdf; policy 403; cross-project attachable 422; storeAs public; Attachment::create with stored file_size + uploaded_by; ActivityLog 'Upload Attachment'; JSON payload incl. display_size), delete() (policy 403; ActivityLog 'Delete Attachment'), view() (inline Content-Disposition), download().
- Step 5: `routes/web.php` — ATTACHMENT ROUTES blocks: staff (upload/destroy/view/download), contractor (view/download READ-ONLY), admin (all four). `route:list --name=attachment` shows all 10.
- Step 6: `tests/Feature/AttachmentTest.php` — 13 tests (43 assertions), direct controller invocation per codebase convention.
- Step 7: Verification — `php -l` clean on all new files; `php artisan test --filter=AttachmentTest` 13/13; full suite 211 passed / 735 assertions (baseline 198/692 — no regressions).

### 2026-08-02 — Environment findings (deviations from plan)
1. **Route-based authenticated requests do NOT authenticate in this test environment** — the `auth` middleware reports `401 Unauthenticated` even after `actingAs()` (confirmed via a throwaway GET `/admin/dashboard` and a JSON request returning `{"message":"Unauthenticated."}`). This is a PRE-EXISTING repo-wide condition — every existing test (ProgressDeleteGuardTest, PredictionHistorySyncTest) invokes controllers directly for this reason. AttachmentTest therefore follows that convention: direct controller calls; only the guest-redirect assertion uses a real route.
2. **GD extension is not installed** — `UploadedFile::fake()->image()` throws `LogicException: GD extension is not installed.`; replaced with `UploadedFile::fake()->createWithContent(...)` using real JPEG magic bytes so the content-based `mimes` rule passes.
3. **`User::factory()` unusable** — the `users` table has no `email_verified_at` column, but the factory sets it; users are created via `User::create([...])` exactly as the reference test does.
4. **Role-scoped routes require the matching actor role** — posting to `staff.*` as an admin redirects (RoleMiddleware → unauthorized), so tests route by actor role; contractor policy guards are exercised via direct controller calls.

---

END OF PHASE A PLAN

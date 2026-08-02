# Core Project Monitoring Completion Plan

CSPC PMU System — Core Monitoring (AI regression module paused)

Status: 📋 PLANNED — not started. User decided to shift focus from the
        regression-based predictive module to finishing the core project
        monitoring features first. Regression module paused (Phase 8 done;
        Fix H + v2.5 deferred to backlog).

Date: August 2, 2026

Branch base: `main` @ `7086afe` (fixes A–G already merged & pushed; the
        `fix/progress-additional-fixes` branch tip == main — no pending merge)

Reference:
- textfiles/active_reference/project_context_and_development_rules.txt (coding standards, Rule 8/10/11/12)
- textfiles/active_reference/ai_module_ver2_master_plan.txt (V2 master plan — paused)
- textfiles/regression_v2_5_cross_project_expansion_plan.md (Fix H / v2.5 — backlog)
- textfiles/gaps_and_features_missing.txt (placeholder/feature gaps)
- textfiles/ai_recommendation_data_gaps.txt (Recommendation Outcome Feedback Loop — future Phase 10)

---

## 1. WHY THIS PLAN EXISTS

The regression-based predictive module is functionally complete for V2 (Phase 8
implemented + health-gauge fixes merged into main). Remaining items there (Fix H,
v2.5 expansions, Phase 8 open items) are polish/research, not blocking.

The core project monitoring pages still contain hardcoded placeholder content:

- `resources/views/projects/project_detail/components/tabs/overview.blade.php`
  renders four fake cards: **Issues & Risks**, **Notifications & Alerts**,
  **Documents**, **Recent Activity** (mockup arrays at lines 87–104, static
  timeline at lines 287–290, labeled "Mockup preview" / "Mockup timeline").

Three of those cards have real data already available in the system (Issue
model, `$data['documents']`, `ActivityLog`); only the Alerts card has no data
source. Additionally:

- The **Issues** feature has no resolution content, no attachment, and no
  linkage to a baseline month.
- **Progress recording** accepts no file evidence (inspection photos/PDFs).
- The AI analysis (`ai_solutions`) is not linked to the baseline month it was
  generated against — needed later to evaluate recommendation outcomes.

Goal of this plan: complete the core monitoring features with real, database-
backed data, and lay the data groundwork (issue ↔ baseline, analysis ↔ baseline)
for the future Phase 10 recommendation-outcome feedback loop.

---

## 2. CURRENT STATE (verified 2026-08-02)

- `main` = `7086afe`; fixes A–G + auto-complete at 100% are merged and pushed.
- `ActivityLog` has **no `project_id`** — global audit trail only.
- `documents` table is a **formal, type-categorized project library**
  (`document_type_id` required, `onDelete('restrict')`). Attachments (inspection
  photo, issue evidence) are a different concept → **new polymorphic
  `attachments` table** (decision 4).
- `recordMonthlyProgress()` posts **JSON** today (no file support); moving to
  `FormData` is required for attachments.
- `prediction_history` already stores `baseline_schedule_id` (predicted month)
  and `actual_next` vs `predicted_next` — the future outcome data.
- Recommendations are **rule-based** (`RecommendationEngine`), not AI — no
  outcome evaluation is meaningful yet (decision 5).

---

## 3. CONFIRMED DECISIONS

1. **Focus shift** — core project monitoring first; regression module paused.
2. **Alerts card** — derive alerts from real signals (no new table).
3. **Recent Activity card** — add nullable `project_id` to `activity_logs`
   (FK, nullOnDelete) and populate it in the project-scoped log calls.
4. **Attachments** — new generic polymorphic `attachments` table (NOT the
   existing `documents` table).
5. **Feedback loop** — preparation only for now (issue ↔ baseline, analysis ↔
   baseline). No recommendation snapshot, no outcome display until Phase 10.
6. **Issue resolution** — `resolution_notes` (text) + optional attachment;
   notes required when status becomes Resolved/Closed; `resolved_at` reused as
   the resolution date (already auto-managed in `IssueController`).
7. **Progress attachments** — multiple files per inspection; whitelist
   `jpg,jpeg,png,pdf`, max 20 MB.

---

## 4. PHASE A — Attachments Infrastructure

Branch: `core/attachments-infra` (from `main`)

### Migration — `create_attachments_table`
| Column | Type |
|--------|------|
| `id` | bigint PK |
| `attachable_type` | string(255) |
| `attachable_id` | bigint unsigned |
| `project_id` | FK → projects, onDelete cascade |
| `context` | string(20) nullable — `inspection` \| `issue` \| `resolution` |
| `original_name` | string |
| `file_path` | text |
| `file_size` | bigint unsigned nullable (bytes) |
| `mime_type` | string(100) nullable |
| `uploaded_by` | string nullable (user name) |
| `created_at` / `updated_at` | timestamps |

Indexes: `(attachable_type, attachable_id)`, `(project_id)`.

### Files
- `app/Models/Attachment.php` — `morphTo()`, `belongsTo(Project)`,
  `file_url` / `file_size` / `file_extension` accessors, `booted()` deleting
  hook removes physical file (mirrors `app/Models/Document.php`).
- `app/Http/Controllers/AttachmentController.php` — upload / delete / view /
  download. Validation: `file` `required|file|mimes:jpg,jpeg,png,pdf|max:20480`,
  `attachable_type` in `[App\Models\ProjectProgressHistory, App\Models\Issue]`,
  `attachable_id` integer, `context` in `[inspection, issue, resolution]`.
- Routes in `routes/web.php` — staff/admin upload + delete; all roles
  view/download (mirror Document routes).
- Storage: disk `public`, path
  `projects/attachments/{PROJECT_CODE}/{attachable_type}/{timestamp}-{uniqid}.{ext}`.

### Tests
- upload stores file + returns URL
- rejects non-image/pdf and >20MB
- delete removes DB row + physical file
- project cascade cleans up rows
- context / attachable validation

---

## 5. PHASE B — Overview Real Data

Branch: `core/overview-real-data` (from `main`)

### Migration
- `activity_logs`: add nullable `project_id` (FK → projects, nullOnDelete), index.

### Populate project_id
- Add `project_id` to the project-scoped `ActivityLog::create(...)` calls in:
  `ProjectProgressController`, `DocumentController`, `BaselineScheduleController`,
  `ProjectActivityController`, `ProjectExtensionController` (add missing calls —
  summary_of_next_work.txt §6), and the new `IssueController` calls from Phase C.

### Service — `ProjectOverviewService`
- `getRecentActivity(Project)` → latest ~6 `ActivityLog` rows scoped by
  `project_id`, joined with user name.
- `getAlerts(Project)` → derived alerts:
  - next pending inspection month (first baseline month with no progress record)
  - aging / open issues (count + worst severity)
  - pending extension requests (`project_extensions.status = 'pending'`)
  - document-coverage reminder when `total_documents == 0`

### View — `overview.blade.php`
- Rewrite the four cards: **Issues & Risks** (top 3 open by severity + count →
  link to Issues tab), **Documents** (latest 3 from `$data['documents']` +
  `total_documents`), **Recent Activity** (real timeline), **Notifications &
  Alerts** (derived list).
- Remove `$issueMockups` / `$alertMockups` / `$documentMockups` / static
  timeline and the `Mockup preview` / `Mockup timeline` labels.

### Tests
- `getAlerts` / `getRecentActivity` shapes
- overview renders no hardcoded placeholder strings

---

## 6. PHASE C — Issue Lifecycle Upgrade

Branch: `core/issue-lifecycle` (from `core/attachments-infra`)

### Migration
- `issues`: add `baseline_schedule_id` (nullable FK → project_baseline_schedules,
  nullOnDelete) + `resolution_notes` (text, nullable).

### Files
- `app/Models/Issue.php` — add to `$fillable`; `baselineSchedule()` belongsTo;
  `attachments()` morphMany; `scopeOpen` for unresolved.
- `app/Http/Controllers/IssueController.php` — `getIssues()` JSON now includes
  `resolved_at`, `resolution_notes`, `baseline_schedule_id`, linked `month_label`;
  validate `baseline_schedule_id` belongs to the project; require
  `resolution_notes` when status is Resolved/Closed (store + update); add
  `ActivityLog::create` on store/update/destroy.

### Views
- `issues_modal.blade.php` — add baseline-month dropdown (project baselines,
  ordered by planned_date) + resolution notes textarea (shown when
  Resolved/Closed) + file input(s) for `context: 'issue'` and `context:
  'resolution'`.
- `issues.blade.php` — add "Month" column + resolution column; issue cards show
  attachment chips with preview (reuse `document-viewer-modal`).

### Tests
- resolution-required rule
- baseline link must belong to project
- attachments attach to issue + resolution
- JSON exposes new fields

---

## 7. PHASE D — Progress Recording Attachments

Branch: `core/progress-attachments` (from `core/attachments-infra`)

### Files
- `app/Http/Controllers/ProjectProgressController.php` —
  `recordMonthlyProgress()` and `editProgressRecord()` accept optional
  `attachments[]` (multiple, image/pdf); persist as `Attachment` rows
  (`context: 'inspection'`) after the record commit; return `attachments` in
  responses.
- `record_monthly_progress_modal.blade.php` + `baseline-schedule-record-progress-modal.js`
  — switch request body from JSON to `FormData` (adds file inputs).
- `baseline-schedule-edit-progress-modal.js` / edit modal — same change.

### Views
- `progress.blade.php` history table + baseline monthly cards show attachment
  chips (image thumbnail / PDF icon) with preview via `document-viewer-modal`;
  add/remove attachment UI on the record.

### Tests
- multipart record with 0 / N files
- mime whitelist (image/pdf only)
- attachment rows created + linked to the progress record
- edit adds attachments

---

## 8. PHASE E — AI ↔ Baseline Linkage (Preparation Only)

Branch: `core/ai-baseline-linkage` (from `core/issue-lifecycle`)

### Migration
- `ai_solutions`: add nullable `baseline_schedule_id` (FK →
  project_baseline_schedules, nullOnDelete) = the **current pending baseline**
  (first baseline month with no progress record yet) at generation time.

### Files
- `app/Services/AI/AIExplanationService.php` — `saveAnalysis()` stores
  `baseline_schedule_id` (resolved from the pending baseline).
- `ProjectController::regenerateAnalysis()` passes it through.

### Out of scope (deferred to Phase 10)
- No recommendation snapshot, no "recommendation followed" flag, no outcome
  display. Analysis stays regeneration-only.

### Tests
- `ai_solutions.baseline_schedule_id` set to the current pending baseline on save

---

## 9. BRANCHING & PROCESS (per project rules)

| Phase | Branch | Base |
|-------|--------|------|
| A — Attachments infra | `core/attachments-infra` | main |
| B — Overview real data | `core/overview-real-data` | main |
| C — Issue lifecycle | `core/issue-lifecycle` | `core/attachments-infra` |
| D — Progress attachments | `core/progress-attachments` | `core/attachments-infra` |
| E — AI↔baseline linkage | `core/ai-baseline-linkage` | `core/issue-lifecycle` |

- Rule 8 — test each phase (PHPUnit for services/controllers, browser for views)
  before proceeding to the next.
- Rule 10 — inform the user after each phase completes.
- Rule 11 — update this plan + progress log each phase.
- Rule 12 — do NOT merge to main without explicit approval.

---

## 10. DEFERRED / FUTURE WORK (Backlog)

- **Fix H** — non-monotonic polynomial: next prediction can drop below current
  progress; completion can solve to a past date. See
  `textfiles/progress_inspection_refactor_plan.md` §3 + v2.5 Option A2.
- **Phase 8 open items** — health gauge at gap=0; `completion_multi` render in
  supplement mode.
- **Phase 10 (future)** — AI-powered recommendations. When built, the groundwork
  here (issue ↔ baseline, prediction_history ↔ baseline, ai_solutions ↔ baseline,
  issue resolution) enables: persisting recommendation snapshots, "recommendation
  followed" flags, and actual-vs-predicted outcome metrics (addresses
  `ai_recommendation_data_gaps.txt` — Recommendation Outcome Feedback Loop).

---

## 11. DECISION LOG

1. **Focus shift** — core monitoring first; regression module paused.
   Context: core pages still show hardcoded placeholders; regression is
   functionally complete for v2.
   Decision: build the five phases above first.
   Consequences: Phase 10 feedback-loop groundwork is laid as part of this work.

2. **New attachments table vs reuse documents** — new polymorphic table.
   Context: `documents` is a type-categorized formal library with a required
   `document_type_id` (RESTRICT) — a poor fit for record-level evidence files.
   Decision: new `attachments` table with morph + project_id + context.
   Consequences: one extra table/controller; both share the `public` disk and
   `document-viewer-modal` preview.

3. **Recent Activity source** — add `project_id` to `activity_logs`.
   Context: ActivityLog is global (no project scope).
   Decision: nullable FK + populate project-scoped call sites.
   Consequences: ~8 controllers touched; true project-filtered audit timeline.

4. **Feedback loop scope** — preparation only.
   Context: recommendations are rule-based, not AI; outcomes not meaningful yet.
   Decision: linkage only (issue ↔ baseline, ai_solutions ↔ baseline); no
   outcome metrics until Phase 10.
   Consequences: prediction_history's existing predicted-vs-actual data is
   preserved and ready for Phase 10.

---

END OF PLAN (draft — not started)

# EXTENSION MANAGEMENT — COMPREHENSIVE UPGRADE PLAN

CSPC PMU System — `phase-extension-management` branch (off `main`)

Status: 📋 PLAN CREATED — implementation not started
Branch: `phase-extension-management` (created off `main` after `phase-ui-polish` was merged)

Rules: textfiles/active_reference/project_context_and_development_rules.txt (Rule 8/10/11/12)

---

## 1. OBJECTIVE

Upgrade the project extension management module end-to-end:

- Allow **multiple pending extension requests** (a queue), with defined
  behavior when one is approved.
- Improve **request quality**: structured reason categories, optional
  evidence attachments, review-aging visibility.
- Make **reverts/deletes safe against the adjusted baseline** by snapshotting
  the baseline schedule per extension and restoring it correctly.
- Add **resubmit-as-new-row**, **partial approval**, **soft delete with an
  archived panel**, and fix the admin validation gap.
- Hide the contractor "Request Extension" button (contractors have no write
  routes today — the button currently 404s).

All phases on ONE branch (`phase-extension-management`), no merge to `main`
without explicit approval (Rule 12).

---

## 2. CURRENT STATE & PROBLEM

### Current flow (confirmed)
1. Staff/admin submit an extension request; staff submissions go `pending`.
2. Admin approves → `project.planned_end_date` / `extension_count` update.
3. User manually runs "Generate Adjusted Baseline"
   (`BaselineScheduleController::saveAdjustedBaseline()`, line 699), which
   stretches the current plan to the new deadline and **can be manually
   edited** before saving. Each generation builds on the current live plan
   (which is the previous extension's adjusted state).

### Known gaps (to be fixed by this plan)
- **Only one pending extension allowed** (`ProjectExtensionController::store()`,
  lines 36-44) — contradicts real construction practice where multiple delay
  events are claimed concurrently.
- **Reverting an approved extension leaves the adjusted baseline stale** —
  only `recomputeProjectDeadline()` (line 475) runs; `planned_progress_percent`
  stays stretched to the reverted extension's timeline, so the S-Curve
  "Adjusted Plan" line no longer matches the rolled-back deadline.
- **No structured reason category / evidence / review-aging** on requests.
- **Resubmitting a rejected request mutates the same row** and wipes
  `rejection_reason` — no revision history.
- **Admin `update()` validation gap**: admin `new_end_date` only capped at
  2030 (lines 287-291); staff branch requires `after: planned_end_date`
  (line 226) but the admin branch does not — an admin can approve a date
  *before* the live deadline and accidentally roll it back.
- **Contractor sees a "Request Extension" button** that has no POST route
  (`routes/web.php` contractor group has only `index`/`show`, lines 332-337)
  — submitting 404s.
- **No soft delete** — deleting an extension permanently destroys history.

---

## 3. DECISION LOG

Decision 1 — **Allow multiple pending extensions (proposal queue)**
  Context: industry EOT practice allows concurrent claims tied to distinct
  delay events; Gemini pointed out the one-pending rule is unrealistic.
  Options: single-pending (status quo) / proposal queue (recommended) /
  additive chain.
  Decision: **Proposal queue.** N pending allowed; each is a proposal against
  the *current approved* deadline. A new pending request still requires
  `new_end_date` after the current approved deadline.
  Rationale: matches industry reality at low complexity; avoids the additive
  chain (which the S-curve/baseline code does not model).
  Consequences: on approval, remaining pendings must be re-anchored or marked
  superseded (see Decision 2).

Decision 2 — **On approval: re-anchor valid pendings; mark obsolete ones `superseded`**
  Context: approving request A can invalidate other pendings' basis.
  Options: auto-cancel / auto-supersede (kept visible).
  Decision: **Auto-supersede.** After an admin approves an extension:
  - Remaining pendings whose `new_end_date` is still AFTER the new live
    deadline → re-anchor `original_end_date` to the new deadline and
    recompute `duration_days`.
  - Remaining pendings whose `new_end_date` ≤ new live deadline → status
    becomes `superseded` (kept visible in history with a badge; not
    approvable; deletable).
  Rationale: preserves the audit trail (kept visible) while preventing
  ambiguous competing deadlines.
  Consequences: `status` enum gains `superseded` (requires an ALTER migration —
  current enum is `pending/approved/rejected`, see
  `2026_06_24_075435_add_status_to_project_extensions_table.php`).

Decision 3 — **Reason category is an addition to the free-text reason**
  Context: request-quality improvement.
  Decision: new `reason_category` column (dropdown: Weather, Materials,
  Labor, Site access, Permits, Design change, Other). Free-text `reason`
  remains the detailed justification. Mirrors EOT "grounds + narrative".

Decision 4 — **Extension evidence = attachments (not documents)**
  Context: evidence vs document question. Documents are project-level
  (contract/supporting); issues/risks/progress already use the polymorphic
  `Attachment` model for record-level evidence.
  Decision: reuse `Attachment`. Add `'extension'` to `Attachment::CONTEXTS`
  (line 20) and `ProjectExtension::class` to `Attachment::ALLOWED_ATTACHABLE_TYPES`
  (line 24). Upload via the EXISTING `AttachmentController::upload()`
  (validates context + attachable_type, line 26-36) using a two-step flow:
  create/update the extension first, then upload evidence referencing the
  extension id. Modal submission switches from JSON to `FormData`/multipart
  for the file part (mirrors issue/risk modals).

Decision 5 — **Review aging**
  Decision: show "N days awaiting review" on pending rows + remind admins
  when a pending request has sat unreviewed (reuses `NotificationService`;
  mirrors FIDIC 42-day assessment-window intent).

Decision 6 — **Resubmit = new row, never mutate the rejected row**
  Context: Gemini recommended not editing a rejected request in place.
  Decision: a "Resubmit" action copies the rejected extension's details into
  a NEW extension row (next `extension_number`); the rejected row stays
  intact with its `rejection_reason`. `extension_number` is a per-row
  sequence. "Total Extensions" card + `extension_count` are computed from
  APPROVED rows only (align `recomputeProjectDeadline` so `extension_count`
  reflects the approved count, not just the highest approved number).

Decision 7 — **Partial approval**
  Context: real EOT claims are often granted less than requested; a single
  `new_end_date` destroys the request record when an admin grants less.
  Decision: new nullable `approved_new_end_date` column. `new_end_date`
  stays as requested; the project deadline uses `approved_new_end_date`
  when present, else `new_end_date`. Admin approves as-is (approved =
  requested) or sets a lower granted date. `recomputeProjectDeadline()` and
  the extensions-section "Current Deadline" card read the approved date.

Decision 8 — **Admin validation gap fix**
  Decision: add `'after:' . $project->planned_end_date->format('Y-m-d')` to
  the admin `new_end_date` rule in `update()` (mirrors staff branch, line 226).
  An admin approving/reverting can never set a date before the live deadline.

Decision 9 — **Baseline snapshot + restore (see §4 for full design)**
  Decision: JSON column `baseline_snapshot` on `project_extensions`, captured
  in `saveAdjustedBaseline()`; restore from the previous approved extension's
  snapshot when the ACTIVE approved is reverted; fall back to the original
  plan when no snapshot exists. Block the revert if the restore would delete
  a month that has recorded progress.

Decision 10 — **Revert-first delete guard**
  Context: the last UI polish round let admins delete non-active approved rows.
  Decision: **no approved extension is directly deletable** — must first be
  reverted (→ pending/rejected), then deleted. `destroy()` becomes
  approved-proof (no baseline restore needed there; the only restore trigger
  is `update()` reverting the ACTIVE approved). Protects the snapshot chain.

Decision 11 — **Non-active approved revert: BLOCKED for now**
  Context: user asked what happens if the active is reverted but older ones
  were already reverted. The cascade resolves it (see §4.4): revert the
  active → the previous approved becomes active → revert it → … → original.
  Decision: only the ACTIVE (latest approved) extension can be reverted.
  Reverting a non-active approved is blocked (403) for now.
  Consequences: cleaning up an incorrectly-approved OLDER extension is
  awkward (requires cascading reversion). A bulk operation (bulk
  revert/delete) is deferred to the next enhancement (see §8).

Decision 12 — **Partial approval / soft delete / contractor all included**
  - Soft delete: `SoftDeletes` on `ProjectExtension` + `deleted_at`.
    Normal delete → soft. New **Archived Extensions** panel (admin) with
    Restore + Delete Forever (also a dev/test-data escape hatch).
  - Contractor: hide the "Request Extension" button for contractors for now
    (trivially reversible if the client later wants contractor submissions).

---

## 4. BASELINE SNAPSHOT DESIGN (detailed)

### 4.1 What a snapshot is
A snapshot is the **entire baseline schedule table state** serialized to JSON,
captured at the moment an adjusted baseline is saved for an extension. It is
a **restore point, not a live mirror** — it is NEVER synced on individual
month add/edit/delete. This resolves the "no table connection / must sync
every edit" concern: there is no per-row link, and no continuous sync.

Per month, the snapshot stores: `month_label`, `planned_date`,
`planned_progress_percent`, `original_planned_progress_percent`, `description`,
and activities (for full-fidelity restore).

### 4.2 Capture timing (matches the confirmed flow)
| Step | Baseline state at that moment | Snapshot stored on |
|---|---|---|
| Baseline set | Original frozen plan | — |
| Ext #1 approved (→ Feb) | Generate adjusted baseline → plan stretched to Feb | **Snapshot A on ext #1** |
| Ext #2 approved (→ Apr) | Generate adjusted baseline again → plan stretched to Apr (built on the Feb state) | **Snapshot B on ext #2** |

So each extension's snapshot is "based on the previous baseline": ext #2's
snapshot is the current plan (ext #1's adjusted state) stretched to ext #2's
deadline. Capture hook: end of `saveAdjustedBaseline()`
(`BaselineScheduleController.php:699`), after commit, attached to the latest
approved extension.

### 4.3 Restore semantics (direction is critical)
Reverting the **ACTIVE (latest) approved** extension restores from the **NEW
latest approved extension's snapshot** — i.e. the PREVIOUS approved, NOT the
reverted extension's own snapshot (the reverted extension's snapshot is the
state being reverted AWAY from). "The adjusted baseline should still be for
ext 1."

- Previous approved HAS a snapshot → **make the live table exactly match it**:
  - Update matching rows (`planned_progress_percent`, `planned_date`,
    `description`, activities).
  - Recreate snapshot rows missing from the DB (deleted during a later
    adjustment — safe: such rows have no progress by the existing guard).
  - Delete current rows NOT in the snapshot (months created for the reverted
    extension).
- Previous approved has NO snapshot (approved but never adjusted), or no
  approved remains → **restore the original plan**: for rows with a non-null
  `original_planned_progress_percent`, set `planned_progress_percent =
  original_planned_progress_percent`; delete extension-only rows
  (`original_planned_progress_percent IS NULL`).

### 4.4 Guard: block when progress would be lost
If restoring would delete a baseline row that has recorded actual progress,
**block the revert (422)** with a message listing the affected months. This
mirrors the existing "cannot remove a month with progress" guard in
`saveAdjustedBaseline()` (line 728). No silent data loss.

### 4.5 Why "block non-active approved revert" is safe (the cascade)
Reverting the active approved leaves the previous approved active, so full
undo is a cascade, each step restoring the correct state:
ext #2 active → revert #2 → restore Snapshot A (ext #1 active) → revert #1 →
restore original. The "all others already reverted" case therefore cannot
strand the project.

### 4.6 Documented behaviors (not decisions)
- **Re-approve after revert** moves the deadline only; it does NOT auto-restore
  the plan (the extension's own snapshot may be stale if the date was edited
  while pending). A toast hints the user to re-run "Generate Adjusted Baseline".
- **Approved-but-never-adjusted** extension has no snapshot → reverting a
  later active one falls back to "restore original."

---

## 5. PHASES

All phases on `phase-extension-management`. Test each phase before the next
(Rule 8), inform the user at completion (Rule 10), log here (Rule 11).

### Phase 1 — Queue + guards ✅ COMPLETE
- `store()`: remove the one-pending guard (lines 36-44).
- `update()` admin path: after approval, re-anchor valid pendings +
  mark obsolete pendings `superseded` (Decision 2).
- Admin `new_end_date` `after:` rule (Decision 8).
- `ProjectExtension` model: `superseded` in status helpers + scopes.
- Migration: ALTER status enum → add `superseded`.
- JS/blade: pending queue rendering, superseded badge (not approvable,
  deletable).
- Tests: `ProjectExtensionLifecycleTest` — multi-pending, re-anchor,
  supersede, admin-after-rule.

### Phase 2 — Request quality ✅ COMPLETE
- `reason_category` column + dropdown (Decision 3).
- Evidence attachments (Decision 4): model constants + `attachments()`
  morphMany on `ProjectExtension`, multipart modal (two-step upload),
  evidence list/links in the extensions table; `formatExtension()` includes
  attachment count.
- Review aging (Decision 5): days-awaiting badge + admin reminder.
- Migrations: `reason_category`, (attachment table already exists).
- Tests: category persistence, evidence upload/delete, aging badge render.

### Phase 2.5 — Baseline snapshot + restore ✅ COMPLETE
- Migration: `baseline_snapshot` JSON column on `project_extensions`.
- Capture hook in `saveAdjustedBaseline()` (attaches to latest approved).
- New restore helper in `ProjectExtensionController` implementing §4.3/§4.4;
  called from `update()` on active-approved revert.
- Confirmation dialog on the revert (overwrites manual edits made since the
  snapshot; blocked if progress would be lost).
- Tests: snapshot-then-revert → restores previous adjusted plan; no-snapshot →
  original; approved-never-adjusted; block-when-progress.

### Phase 3 — Resubmit-as-new-row + deletion/revert guards ✅ COMPLETE
- Resubmit = new row (Decision 6): "Resubmit" action on rejected rows,
  copies details into a new row; rejected row untouched.
- Revert-first delete guard (Decision 10): `destroy()` blocks ANY approved
  extension (403/400).
- Non-active approved revert blocked (Decision 11) — only active revertable.
- Align `extension_count` with approved-count semantics.
- Tests: resubmit history, delete-approved blocked, non-active-revert blocked,
  cascade revert.

### Phase 4 — Partial approval ✅ COMPLETE
- `approved_new_end_date` column (Decision 7); admin UI field; deadline
  sources + extensions-section card + `recomputeProjectDeadline()` read the
  approved date.
- Tests: approve-as-requested vs partial-grant deadline behavior.

### Phase 5 — Soft delete + archived panel ✅ COMPLETE
- `SoftDeletes` + `deleted_at` migration (Decision 12).
- Normal delete → soft; **Archived Extensions** panel (admin): Restore +
  Delete Forever.
- Update `ProjectExtensionLifecycleTest`: `assertDatabaseMissing` assertions
  must check `deleted_at`/`withTrashed()` instead (soft-deleted rows remain).
- Tests: soft delete hides row, restore brings it back, permanent delete
  removes it.

### Phase 6 — Contractor + misc ✅ COMPLETE
- Hide "Request Extension" button for contractors (no write routes).
- Re-approve-after-revert toast hint (see §4.6).
- Tests: contractor detail page does not render the Request button.

---

## 6. MIGRATIONS (anticipated)

1. ALTER `project_extensions.status` enum → add `superseded`.
2. ADD `reason_category` (string, nullable) to `project_extensions`.
3. ADD `approved_new_end_date` (date, nullable) to `project_extensions`.
4. ADD `baseline_snapshot` (json, nullable) to `project_extensions`.
5. ADD `deleted_at` (timestamp, nullable) to `project_extensions` (soft deletes).

---

## 7. TESTING PLAN (per phase)

- Backend: `php artisan test --filter=ProjectExtensionLifecycleTest` (+ any
  new test files per phase); full suite `composer test` at the end of each
  phase (Rule 8).
- `node --check` on all edited JS files.
- Browser verification per Rule 8 (queue + supersede badges, evidence
  upload, snapshot revert restore, archived panel, contractor button hidden).

---

## 8. FLAGGED FOR FUTURE (deferred)

- **Bulk operations** (bulk revert/delete of extensions) — user deferred to
  the next enhancement.
- **Pending-proposal preview** on the S-Curve (dashed line showing what the
  deadline would be if a pending request were approved) — "good addition,
  not for now."
- Cleanup of an incorrectly-approved OLDER extension is currently awkward
  (requires cascading reversion); a targeted admin "force-delete approved"
  may be revisited with the bulk operations work.

---

## 9. SESSION LOG

### 2026-08-07 — Plan created
- Confirmed `phase-ui-polish` was already merged into `main` (identical tips
  at `f287397`, local == origin).
- Created branch `phase-extension-management` off `main`.
- Locked decisions 1-12 (see §3), including the baseline snapshot design
  (see §4) after walking through the current flow with the user.
- This plan file committed + pushed on `phase-extension-management`.
- Implementation NOT started.

### 2026-08-08 — Implementation complete (all 6 phases)
- Backend committed as `e2f5922` (6 modified files + 5 migrations).
- Frontend wired: modal (reason_category dropdown, partial-approval granted
  date, Reject toggle, two-step evidence upload), extensions-section
  (contractor button hidden, admin Archived panel, Resubmit button), JS
  (superseded badge, aging badge, evidence links, archived load/restore/
  force-delete, resubmit modal flow).
- `ProjectExtensionLifecycleTest` now 29 tests; full suite 435 passing.
- Revert response carries the §4.6 re-run "Generate Adjusted Baseline" hint.
- Frontend work uncommitted at log time (pending user review).

### 2026-08-08 — Bug-fix round 1: edit-guard equality + archive-first delete
- **Edit guard equality bug**: editing an existing extension whose
  `new_end_date` equals the live deadline (the active approved extension IS
  the deadline) was rejected with "New end date must be after current
  deadline." Fixed both sides to allow equality in edit mode while keeping
  strict `after:` for new/resubmit requests:
  - Backend `ProjectExtensionController::update()` — `new_end_date` (staff +
    admin) and `approved_new_end_date` changed `after:` → `after_or_equal:`.
  - Modal JS `recalculateExtensionDuration`/`submitExtension` — equality
    allowed when `extensionEditMode === 'edit'`; create/resubmit stay strict.
  - Second layer: the date input's native `min` was `deadline + 1`, so
    `checkValidity()` failed ("Please provide a valid end date.") before the
    custom guard ran — `updateModalDeadlines()` now sets `min = deadline` in
    edit mode, `deadline + 1` otherwise.
  - Regression test added: `test_admin_editing_active_approved_can_keep_its_own_date_equal_to_live_deadline`.
    Suite now 436 passing (1570 assertions).
- **Archive-first delete UX**: backend was already archive-first
  (`destroy()` soft-deletes; `forceDestroy()` only reachable on archived
  rows), but the UI said "Delete", used native `confirm()`, and warned "cannot
  be undone" on the reversible step. Reworked `baseline-schedule-extensions.js`:
  - Live-table button renamed **Delete → Archive** (reversible wording,
    reuse of the shared `delete-confirmation-modal` component).
  - `forceDeleteArchivedExtension` (archived panel) also now uses the shared
    confirm modal.
  - Added the previously-missing `buildExtensionsEndpoint()` helper (the old
    `deleteExtension` referenced it but it was never defined — latent bug).
- **CSRF 419 on archive**: `performArchiveExtension`'s DELETE request was
  missing the `X-CSRF-TOKEN` header (toast "CSRF token mismatch"). Added the
  token header (mirrors the force-delete/restore calls).
- JS verified with `node --check`; extension tests 30 passing.

---

END OF PLAN FILE

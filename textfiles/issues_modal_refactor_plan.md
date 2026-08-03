# ISSUES MODAL — JS & CSS SEPARATION REFACTOR PLAN

CSPC PMU System — Project Detail (Issue Lifecycle modals)

Status: 📋 PLANNED — not started
Date: August 3, 2026
Branch: `refactor/issue-modal-separate-assets` (from `main`)

Parent: textfiles/phase_c_issue_lifecycle_upgrade_plan.md (Phase C)
Rules: textfiles/active_reference/project_context_and_development_rules.txt (Rule 2/3/9/12)

---

## 1. OBJECTIVE

`resources/views/projects/project_detail/components/modals/issues_modal.blade.php` currently
holds BOTH the Add & Edit issue dialogs AND long inline `<style>` and `<script>` blocks
(all-blade single file). This plan separates the presentation/behavior assets into their own
dedicated files, keeping the two modals together in the blade (no modal splitting).

Goals:
- Slim down the blade to its markup only.
- Move the CSS to `public/css/admin/projects/issues-modal.css`.
- Move the JS to `public/js/admin/projects/issues-modal.js`.
- Preserve every existing selector, function, and behavior exactly (no logic changes).
- Follow the codebase's existing asset-include convention (inline `<link>`/`<script src>`).

---

## 2. CURRENT STATE (verified)

- Single file: `resources/views/projects/project_detail/components/modals/issues_modal.blade.php`
  - Lines  1–285: HTML markup for `#issuesModal` (Add) + `#editIssueModal` (Edit), incl. `@php`
    blocks computing `$baselines` / `$defaultBaselineId`.
  - Lines 286–508: one `<style>` block (Issue form section, evidence group, chips, select
    chevrons, modal sizing, etc.).
  - Lines 510–1146: one `<script>` block (list rendering, pending-file previews, attachment
    chips, deferred-deletion queue, redirect-to-update flows, reusable delete-confirm wiring,
    toast helpers).
- Included via `@include('projects.project_detail.components.modals.issues_modal')` at
  `project_detail.blade.php:99`.
- Codebase asset conventions (verified):
  - CSS for project pages loads via `asset()` in `resources/views/layouts/app.blade.php`
    (lines 12–14): `css/admin/projects/project-detail.css` etc. New modal CSS will be added
    alongside these.
  - Component JS is included inside the view itself via
    `<script src="{{ asset('js/admin/projects/<name>.js') }}">` (e.g. baseline-schedule tab
    lines 15/18/367/532–547), NOT via Laravel `@stack` (the layout has no `@stack`).

---

## 3. CONFIRMED DECISIONS

1. **Keep the two modals in the blade** — do NOT split `issuesModal` / `editIssueModal` into
   separate files.
2. **Move the `<style>` block (lines 286–508)** to
   `resources/css/admin/projects/issues-modal.css` and load it with an
   `<link rel="stylesheet" href="{{ asset('css/admin/projects/issues-modal.css') }}">` in the
   blade (or register it in `layouts/app.blade.php` CSS list).
3. **Move the `<script>` block (lines 510–1146)** to
   `public/js/admin/projects/issues-modal.js` and include it after the modals' markup with
   `<script src="{{ asset('js/admin/projects/issues-modal.js') }}">`.
4. **No logic changes** — the JS/CSS content is moved verbatim; ordering of function
   declarations and event bindings is preserved so nothing breaks.
5. **Blade `@php` `$baselines` / `$defaultBaselineId`** stay server-side in the blade markup
   (rendered into the `<select>` options and `selected` attribute), as they are today.
6. **JS dependency gating** — the extracted JS already guards with `?.` and
   `typeof X === 'function'` checks (e.g. `loadTabIssues`, `renderEditIssueAttachmentList`,
   `showDeleteConfirmation`, `openDocumentViewer`, `getIssuesApiUrl`, `showToast`). These are
   preserved so the file can be loaded globally without context errors.

---

## 4. IMPLEMENTATION STEPS (ordered; test each before the next — Rule 8)

### Step 1 — Extract the CSS file
- NEW: `resources/css/admin/projects/issues-modal.css`
  - Contents = verbatim copy of the `<style>` block (issues_modal.blade.php lines 286–576),
    minus the `<style>` / `</style>` tags.
- Keep selectors scoped to `#issuesModal` / `#editIssueModal` prefixes where they already are.
- Verify: the modal still renders identically (browser check).

### Step 2 — Extract the JS file
- NEW: `public/js/admin/projects/issues-modal.js`
  - Contents = verbatim copy of the `<script>` block (issues_modal.blade.php lines 510–1146),
    minus the `<script>` / `</script>` tags.
- Verify: `node --check public/js/admin/projects/issues-modal.js` passes.

### Step 3 — Slim the blade + add the asset includes
- MODIFIED: `resources/views/projects/project_detail/components/modals/issues_modal.blade.php`
  - Remove the entire `<style>...</style>` block.
  - Remove the entire `<script>...</script>` block.
  - Add near the top (so CSS applies before render) / bottom (JS after DOM):
      - `<link rel="stylesheet" href="{{ asset('css/admin/projects/issues-modal.css') }}">`
      - `<script src="{{ asset('js/admin/projects/issues-modal.js') }}"></script>`
  - Keep the two modals' HTML markup and their `top @php` blocks intact (blade comment +
    modals). The file should now contain only markup + the two asset includes.
- Verify: `php artisan view:cache` compiles (whole app compiles cleanly).

### Step 4 — Update layout asset list (optional/cleaner)
- MODIFIED: `resources/views/layouts/app.blade.php` — add
  `<link rel="stylesheet" href="{{ asset('css/admin/projects/issues-modal.css') }}">` near the
  other `project-detail.css` includes (lines 12–14) so the CSS loads globally alongside the
  existing project-detail styles. (JS may remain inline-modal-included or likewise moved;
  confirm with user to avoid over-broadening — baseline-schedule keeps JS inline.)

### Step 5 — Tests / Verification
- `php artisan test --filter=IssueLifecycleTest` — still green (18/18).
- Full suite — no regressions (last baseline ~251 passed / 871 assertions).
- Browser: open Issues tab → Add Issue + Edit Issue modal, add/edit/delete attachment,
  confirm deferred delete still works, CSS present.

---

## 5. FILES TOUCHED

NEW:
- `resources/css/admin/projects/issues-modal.css`
- `public/js/admin/projects/issues-modal.js`

MODIFIED:
- `resources/views/projects/project_detail/components/modals/issues_modal.blade.php` (remove
  Style+Script; add asset includes)
- `resources/views/layouts/app.blade.php` (register CSS include)

NOT touched:
- `resources/views/projects/project_detail/components/tabs/issues.blade.php` — the issues TAB
  (table) has its own markup/JS; it already lives mostly in the tab view, NOT in this modal.
- `routes/web.php`, controllers.

---

## 6. OPEN QUESTIONS / STILL TO RESOLVE

1. **JS include location** — keep the `<script>` include inside `issues_modal.blade.php`
   (matches how `baseline-schedule` includes its JS inline) vs. move JS include into
   `layouts/app.blade.php`. Default: keep JS include inside the modal blade (recommended,
   matches the baseline-schedule precedent; CSS moved to the layout so it preloads).
2. **CSS location** — dedicated `issues-modal.css` (new) vs. merge into existing
   `project-detail.css`. Default: new dedicated file (keeps separation clean, easier to
   maintain).
3. **Naming** — `issues-modal.css/.js` (default) is consistent with `baseline-schedule-*.js`.

---

## 7. PROCESS NOTES

- Rule 12: work only on `refactor/issue-modal-separate-assets`; do NOT merge to main without
  explicit user approval.
- Rule 8: test after each step (Step 1 → 5 in order).
- Rule 10: inform the user after the phase completes.
- Rule 11: log progress in a session log section as each step lands.
- Plan-only commit BEFORE any code (established pattern).

---

## 8. SESSION / CHANGES LOG

(Chronological log of what is built and bugs found — append as the phase is implemented.)

### 2026-08-03 — Plan created (plan-only, NOT committed to a feature branch yet)
- Branch `refactor/issue-modal-separate-assets` created from `main`.
- Verified current inline CSS (issues_modal.blade.php 286–508) and JS (510–1146) boundaries and
  the codebase's asset-include conventions (layout CSS list + baseline-schedule inline JS).
- Wrote this plan to `textfiles/issues_modal_refactor_plan.md`.

---
END OF PLAN
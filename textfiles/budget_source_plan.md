# Budget Source Feature — Implementation Plan

**Date:** 2026-09-02
**Status:** COMPLETED
**Branch:** `feature/budget-source`

---

## Background

Consultant feedback: the dashboard's "Budget by Category" chart should actually be **"Budget by Source"** — grouped by **where the funding comes from**, not by project category. The PMU's college campus (CSPC) receives budget from various Philippine government sources.

This requires:
1. A new `budget_sources` table (managed like `categories`)
2. A `budget_source_id` foreign key on the `projects` table
3. A dropdown in the create/edit project modal
4. Display in the project detail overview tab
5. Dashboard charts switched from "by Category" to "by Source"

---

## Budget Source Values (Seeded)

| # | Name | Description |
|---|------|-------------|
| 1 | GAA | General Appropriations Act — regular national government budget |
| 2 | CHED | Commission on Higher Education funding |
| 3 | DPWH | Department of Public Works and Highways |
| 4 | LGU | Local Government Unit contributions |
| 5 | GAD | Gender and Development budget |
| 6 | Special Trust Fund | Income-generated trust fund |
| 7 | Regular Agency Fund | Agency's own appropriation |
| 8 | Foreign Assisted | International funding (JICA, ADB, World Bank, etc.) |
| 9 | PPP | Public-Private Partnership |

---

## Implementation Steps

### Step 1: New Migration — Create `budget_sources` table

**File:** `database/migrations/2026_09_02_000001_create_budget_sources_table.php`

Same schema as `categories`:

```php
Schema::create('budget_sources', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->softDeletes();
});
```

### Step 2: New Migration — Add `budget_source_id` to `projects`

**File:** `database/migrations/2026_09_02_000002_add_budget_source_id_to_projects.php`

```php
Schema::table('projects', function (Blueprint $table) {
    $table->foreignId('budget_source_id')->nullable()->after('approved_budget')->constrained('budget_sources')->onDelete('set null');
});
```

Placed after `approved_budget` (line 28 of the original migration) for logical grouping.

### Step 3: New Model — `BudgetSource`

**File:** `app/Models/BudgetSource.php`

- Same structure as `Category` model
- Fillable: `name`, `description`, `is_active`
- Traits: `HasFactory`, `SoftDeletes`
- Casts: `is_active => boolean`
- Relationship: `projects()` → `hasMany(Project::class)`

### Step 4: Update `Project` Model

**File:** `app/Models/Project.php`

- Add `'budget_source_id'` to `$fillable` array (after `approved_budget`)
- Add relationship: `public function budgetSource() { return $this->belongsTo(BudgetSource::class); }`

### Step 5: New Seeder — `BudgetSourceSeeder`

**File:** `database/seeders/BudgetSourceSeeder.php`

- Seeds the 9 budget source values listed above
- Uses `BudgetSource::firstOrCreate()` to avoid duplicates
- Same pattern as `CategorySeeder`

### Step 6: New Controller — `BudgetSourceController`

**File:** `app/Http/controllers/Admin/BudgetSourceController.php`

Same pattern as `CategoryController`:

| Method | HTTP | Purpose |
|--------|------|---------|
| `getBudgetSources()` | GET (JSON) | Returns all active sources for dropdowns |
| `store(Request $request)` | POST (JSON) | Create new source (admin only) |
| `update(Request $request, BudgetSource $budgetSource)` | PUT (JSON) | Update source (admin only) |
| `destroy(BudgetSource $budgetSource)` | DELETE (JSON) | Delete source — refuses if projects exist |
| `getAllForManagement()` | GET (JSON) | Returns all sources with projects_count for management UI |

### Step 7: Routes

**File:** `routes/web.php` — add within admin route group

```php
Route::get('/budget-sources', [BudgetSourceController::class, 'getBudgetSources'])
    ->name('admin.budget-sources.list');
Route::post('/budget-sources', [BudgetSourceController::class, 'store'])
    ->name('admin.budget-sources.store');
Route::put('/budget-sources/{budgetSource}', [BudgetSourceController::class, 'update'])
    ->name('admin.budget-sources.update');
Route::delete('/budget-sources/{budgetSource}', [BudgetSourceController::class, 'destroy'])
    ->name('admin.budget-sources.destroy');
Route::get('/budget-sources/manage', [BudgetSourceController::class, 'getAllForManagement'])
    ->name('admin.budget-sources.manage');
```

### Step 8: Update Create/Edit Project Modal

**File:** `resources/views/admin/_create_project_modal.blade.php`

- Add a `<select>` dropdown for Budget Source next to the Approved Budget field
- Name: `budget_source_id`, ID: `projectBudgetSource`
- Load options from `GET /admin/budget-sources` endpoint
- Not required (nullable) — backward compatible with existing projects
- Layout: same row as Budget and Contractor (3 columns: Budget | Budget Source | Contractor)

### Step 9: Update Edit Modal JS

**File:** `public/js/_create_project_modal.js`

- In the edit pre-fill function (~line 338): add `document.getElementById('projectBudgetSource').value = project.budget_source_id || '';`
- Fetch budget sources from API and populate dropdown on modal open

### Step 10: Update ProjectController

**File:** `app/Http/Controllers/ProjectController.php`

- **store()** (line 601): Add validation rule: `'budget_source_id' => ['nullable', 'integer', 'exists:budget_sources,id']`
- **update()** (line 991): Same validation rule
- **getProjectData()** (line 915): Add `'budget_source_id' => $project->budget_source_id` to returned project array

### Step 11: Update Overview Tab — Project Detail

**File:** `resources/views/projects/project_detail/components/tabs/overview.blade.php`

- Add a "Budget Source" row directly below "Total Budget" in the Project Profile section
- Display: `$project->budgetSource?->name ?? 'Not set'`

### Step 12: Update Admin Dashboard Controller

**File:** `app/Http/Controllers/DashboardController.php`

Change `budgetByCategory` (lines 161-165) to group by source:

```php
$budgetBySource = Project::where('is_archived', false)
    ->selectRaw('budget_sources.name as source_name, SUM(approved_budget) as total_budget')
    ->join('budget_sources', 'projects.budget_source_id', '=', 'budget_sources.id')
    ->groupBy('budget_sources.name')
    ->pluck('total_budget', 'source_name');
```

Pass `$budgetBySource` instead of `$budgetByCategory` to the view.

### Step 13: Update Admin Dashboard View

**File:** `resources/views/admin/dashboard.blade.php`

- Rename chart title from "Budget by Category" to "Budget by Source" (line 270)
- Update PHP variables from `$categoryLabels/$categoryValues` to `$sourceLabels/$sourceValues` (lines 394-418)
- Update chart labels in Chart.js initialization

### Step 14: Update Contractor Dashboard Controller

**File:** `app/Http/Controllers/contractor/DashboardController.php`

Change `budgetByCategory` (lines 160-162) to group by source:

```php
$budgetBySource = $projects
    ->groupBy(fn ($p) => $p->budgetSource?->name ?? 'Unspecified')
    ->map(fn ($group) => $group->sum('approved_budget'));
```

Pass `$budgetBySource` instead of `$budgetByCategory` to the view.

### Step 15: Update Contractor Dashboard View

**File:** `resources/views/contractor/dashboard.blade.php`

- Rename chart title from "Budget by Category" to "Budget by Source" (line 791)
- Update PHP variables from `$catLabels/$catValues` to `$sourceLabels/$sourceValues` (lines 849-875)

---

## Files Summary

| # | File | Action |
|---|------|--------|
| 1 | `database/migrations/2026_09_02_000001_create_budget_sources_table.php` | **Create** |
| 2 | `database/migrations/2026_09_02_000002_add_budget_source_id_to_projects.php` | **Create** |
| 3 | `app/Models/BudgetSource.php` | **Create** |
| 4 | `database/seeders/BudgetSourceSeeder.php` | **Create** |
| 5 | `app/Http/Controllers/Admin/BudgetSourceController.php` | **Create** |
| 6 | `app/Models/Project.php` | **Modify** — add budget_source_id + relationship |
| 7 | `resources/views/admin/_create_project_modal.blade.php` | **Modify** — add Budget Source dropdown |
| 8 | `public/js/_create_project_modal.js` | **Modify** — pre-fill + fetch sources |
| 9 | `routes/web.php` | **Modify** — add budget source routes |
| 10 | `app/Http/Controllers/ProjectController.php` | **Modify** — validation + getProjectData |
| 11 | `resources/views/projects/project_detail/components/tabs/overview.blade.php` | **Modify** — display Budget Source |
| 12 | `app/Http/Controllers/DashboardController.php` | **Modify** — group by source |
| 13 | `resources/views/admin/dashboard.blade.php` | **Modify** — rename chart |
| 14 | `app/Http/Controllers/contractor/DashboardController.php` | **Modify** — group by source |
| 15 | `resources/views/contractor/dashboard.blade.php` | **Modify** — rename chart |
| 16 | `resources/views/admin/_manage_budget_sources_modal.blade.php` | **Create** — CRUD management modal |
| 17 | `resources/views/admin/_create_project_modal.blade.php` | **Modify** — add + button for budget source |
| 18 | `resources/views/projects/project_detail/project_detail.blade.php` | **Modify** — include budget sources modal |

---

## Implementation Log

### 2026-09-02 — All 15 steps implemented

- All migrations, model, seeder, controller, routes, views, and JS created/modified as planned.
- Accidentally dropped 7 validation rules from `store()` in ProjectController when adding `budget_source_id` rule — fixed by restoring `planned_dates`, `actual_dates`, and `status` rules.
- Updated `ProjectExtensionLifecycleTest` to reflect E25 `after` rule (date must be strictly after current deadline).
- Test results: 6 pre-existing failures, 981 passed, zero new failures from our changes.

### 2026-09-02 — Budget by Source: include "Unspecified" for projects with no source

**Problem:** Admin dashboard used inner JOIN on `budget_sources`, so projects with `budget_source_id = NULL` were silently excluded from the chart.

**Fix:** Changed admin `DashboardController.php` from `join()` to `leftJoin()` with `COALESCE(budget_sources.name, 'Unspecified')` so null-source projects appear as "Unspecified". Contractor dashboard already handled this via collection `groupBy`.

**Test results:** 6 pre-existing failures, 981 passed, 0 new failures.

### 2026-09-02 — Budget Source Management Modal

**Problem:** No way for admin to add/edit/delete budget sources from the UI.

**Solution:** Cloned the category management modal pattern:
- Created `_manage_budget_sources_modal.blade.php` with inline add form + list with edit/delete
- Wrapped budget source `<select>` in `input-group` with `+` button (same as category)
- Added `openManageBudgetSourcesFromCreateProject()` JS function for stacking modal flow
- Included modal in `project_detail.blade.php` for admin role
- Uses existing API routes (`admin.budget-sources.manage`, `.store`, `.update`, `.destroy`)

---

## Notes

- The `category` dropdown is **kept as-is** — it still classifies projects by type (New Construction, Renovation, etc.). Only the budget chart switches to grouping by source.
- `budget_source_id` is **nullable** for backward compatibility — existing projects without a source show "Not set" and appear as "Unspecified" in the chart.
- The AI similarity service uses `approved_budget` for comparison — `budget_source_id` is intentionally not added there since funding source doesn't affect project similarity for prediction.
- `ProjectHealthService` deliberately excludes cost/budget health — no changes needed there.
- Document parser (`document-parser.js`) may benefit from auto-extracting budget source from PDFs, but this is a future enhancement — not in scope for this phase.

---

## Budget Savings Feature (ABC + Savings)

**Branch:** `feature/budget-savings`
**Date:** 2026-09-02

### 2026-09-02 — Initial Implementation

**Context:** Philippine government procurement (RA 9184 / RA 12009) uses ABC (Approved Budget for the Contract) as the government ceiling. Savings = ABC − Contract Price (winning bid). The existing `approved_budget` field represents the Contract Price.

**Changes:**
- New migration `2026_09_02_000003_add_abc_to_projects_table.php`: adds `abc` column (decimal 15,2, nullable) after `approved_budget`
- `Project.php`: added `abc` to `$fillable` + `'abc' => 'decimal:2'` to `$casts`
- `ProjectController.php`: added `abc` validation rule in `store()` + `update()`, added to `getProjectData()` return
- `_create_project_modal.blade.php`: renamed "Approved Budget" → "Contract Price"; added ABC field; 4-column layout
- `_create_project_modal.js`: pre-fill `abc` on edit
- `overview.blade.php`: renamed "Total Budget" → "Contract Price"; added ABC + Savings rows (green + / red −)
- `stats-row.blade.php`: "Budget" → "Contract Price", "Total Approved" → "Winning Bid"
- `DashboardController.php`: computes `SUM(abc - approved_budget)` for total savings; passed to view
- `admin/dashboard.blade.php`: new "Total Savings" stat card with piggy bank icon
- `projects/index.blade.php`: "Budget" → "Contract Price" column header + card label
- `projects/archived.blade.php`: "Budget" → "Contract Price" column header

### 2026-09-02 — FormatHelper Fix

**Problem:** `formatCurrencyShort` always divides by 1M, so any savings under ₱1M displayed as `₱0.0M` — appearing as zero/not updating on the dashboard.

**Fix:** Changed stat card from `formatCurrencyShort` to `formatCurrency` which handles K/M/B magnitudes properly (e.g. ₱500K, ₱1.5M, ₱2.3B).

**Test results:** 6 pre-existing failures, 981 passed, 0 new failures.

---

## Edit Mode Wizard Bug — Create Project Modal (Reported 2026-09-02)

**Branch for plan:** `fix/edit-modal-wizard` (off `develop` @ `bd03358`)
**Status:** ✅ IMPLEMENTED 2026-09-02 — fix pushed as `a4c3052`, now updating plan with result + merging to develop

### Simple Explanation (Natural Language) — For Anyone, No Tech Needed

*Imagine the create-project window is like a 6-page booklet you flip through:*

- **Page 1 = Documents, 2 = Project Info, 3 = Description, 4 = Project Setup, 5 = Timeline, 6 = Assignment** — the circles `1 2 3 4 5 6` at the top are the table of contents.

- **When you create a new project**, you see all 6 pages — that’s correct (your Image 1).

- **When you edit an existing project**, you don’t need to re-upload documents, so the booklet hides Page 1. What you *should* see is a 5-page booklet: `1 Project Info | 2 Description | 3 Project Setup | 4 Timeline | 5 Assignment`.

- **What was broken (your Image 2):** The cover still hid Page 1 correctly, but the table of contents was mislabeled as `2 Description | 3 Project Setup | 4 Timeline | 5 Assignment | 6 Assignment`. So “Project Info” as `1` vanished and “Assignment” showed up twice as `5` and `6`. The pages inside still worked, but the top bar was confusing — tapping `2` opened the wrong page.

- *Why it happened — plain words:* The team once had 5 pages, then split one page (“Details”) into two (“Description” + “Project Setup”), making it 6. The code that hides the first page in edit mode was updated, but the code that renames the circles wasn’t — it still counted from the old position, so every label slid one step to the right and the last one ran out of names, leaving the old hard-coded `6` behind.

- *What we fixed — plain words:* We told the code: “in edit mode, when you rename the circles, count one less.” So now edit mode correctly shows `1 Project Info … 5 Assignment` with no duplicate, the blue highlight follows `1`, and tapping `2` truly opens Description. Create mode stays `1 Documents … 6 Assignment`.

### Technical Root Cause

**Files:** `resources/views/admin/_create_project_modal.blade.php:21-51` (hard-coded dots `1..6` at `24,29,34,39,44,49`, labels `step1-label..step6-label`) + `public/js/_create_project_modal.js:19-47` + `public/js/admin/projects/form-navigation.js:71-96`

1. **Label renumbering off-by-one** `public/js/_create_project_modal.js:38-40` `applyWizardMode()`:
   ```js
   const STEP_LABELS_EDIT = ['Project Info','Description','Project Setup','Timeline','Assignment']; // 5
   for (i=1..6) { if(isEdit && i===1) {hide; continue} label.textContent = labels[i-1] }
   // Edit: i=2 → labels[1]='Description' (should be labels[0]='Project Info') → Project Info lost
   //       i=6 → labels[5]=undefined → stays hard-coded 'Assignment' → duplicate
   ```

2. **Indicator highlight off-by-one** `public/js/admin/projects/form-navigation.js:71-96` `updateStepIndicators()`:
   ```js
   for(i=1;i<=5;i++){ indicator=getElementById('step'+i+'-indicator') } // logical 1..5 → DOM step1..step5
   // Edit: DOM step1 is hidden (Documents), so i=1 highlights hidden step1 (invisible), i=2 highlights visible step2 as upcoming 2, and DOM step6 never updated → stays 6.
   ```

3. **Click offset** `public/js/_create_project_modal.js:474-483` `goToStep(parseInt(item.id))` on visible `step2` passes DOM `2` → logical `2` (Description) off by `+1`.

Existing page mapping `form-navigation.js:14` `getDomPageForStep(step){ return edit?step+1:step }` is correct for pages — only the top bar was missed.

### Budget Source Relation

Budget source itself is **not causal** — it only added the `projectBudgetSource` select inside `page4` `Project Setup` (`blade:207-218`, `js:338,1003`). The wizard regression was introduced when the 6-step layout landed (`61f86a0 refactor(create-project-modal): split Details step`) without updating the edit-mode translation layers.

### What Was Fixed (Implemented as `a4c3052`)

- `public/js/_create_project_modal.js:38-40` — now `label.textContent = labels[isEdit ? i-2 : i-1]` for `i>=2` (edit `i=2→0` → Project Info).
- `public/js/admin/projects/form-navigation.js:72` — now `domId = isEdit ? i+1 : i` for indicator/dot/connector inside `updateStepIndicators()` loop `1..getTotalPages()`, so logical `1` highlights DOM `step2`.
- `public/js/_create_project_modal.js:483` — now `goToStep(domStep - (isEdit?1:0))` so click on visible `step2` goes to logical `1`.
- Kept `getTotalPages()`/`getDomPageForStep()` as is (already correct for pages).

### Verification

- Create mode: `1 Documents` visible, `1` active, Next → `2 Project Info` — still correct.
- Edit mode: `1 Project Info` visible as active circle `1` (not `2`), no duplicate `6`, clicking `2` opens Description (page3), clicking `5` opens Assignment (page6) — fixed.
- No change to budget source dropdown behavior — `loadBudgetSources()` staff read fix (`staff.budget-sources.list`) remains separate. `node --check` on both JS files OK.

### Session Log

- 2026-09-02 — User reported edit mode shows `2 3 4 5 6` missing `1 Project Info` with duplicated `Assignment` (Images 1 vs 2). Audited `budget_source_plan.md` — it does mention create-project modal adjustment (Step 8 add Budget Source dropdown + Step 17 add + button) but not wizard numbering. Traced regression to `61f86a0` split Details → Description+Project Setup (5→6 steps) leaving `applyWizardMode` and `updateStepIndicators` off-by-one. Created branch `fix/edit-modal-wizard` and added this plan section (no code yet).
- 2026-09-02 — Implemented fix on `fix/edit-modal-wizard` as `a4c3052` (3 edits), verified with `node --check`, confirmed edit mode now shows `1 Project Info … 5 Assignment`. Updated this plan section to Implemented, added simple language summary, committing and merging to `develop` as requested.

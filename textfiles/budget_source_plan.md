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

---

## Notes

- The `category` dropdown is **kept as-is** — it still classifies projects by type (New Construction, Renovation, etc.). Only the budget chart switches to grouping by source.
- `budget_source_id` is **nullable** for backward compatibility — existing projects without a source show "Not set" and appear as "Unspecified" in the chart.
- The AI similarity service uses `approved_budget` for comparison — `budget_source_id` is intentionally not added there since funding source doesn't affect project similarity for prediction.
- `ProjectHealthService` deliberately excludes cost/budget health — no changes needed there.
- Document parser (`document-parser.js`) may benefit from auto-extracting budget source from PDFs, but this is a future enhancement — not in scope for this phase.

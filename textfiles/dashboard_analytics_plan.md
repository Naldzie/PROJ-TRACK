# Dashboard Analytics Upgrade Plan

**Date:** August 31, 2026
**Status:** PLANNED — consultant feedback
**Branch:** `phase-dashboard-analytics-plan`

---

## Consultant Feedback

"Dashboard should be statistics and analysis — remove activity log."

The current dashboard has a Recent Activity feed (login/logout, edit, create entries) that does not fit the "statistics and analysis" purpose. Replace it with analytics widgets using Chart.js.

---

## Layout Constraint

**Project table must remain easily visible with minimal scrolling.** The analytics grid should sit BETWEEN the stat cards and the projects table, compact enough that the table is reachable without excessive scrolling. Use a tight 3-column layout with minimal padding.

---

## Current vs New Layout

### Current Overview Tab
```
[Stat Cards: Total | Completed | Ongoing | Overdue | At Risk]
[Projects Table (max 10 rows)]
[Recent Activity Feed] ← REMOVE
```

### New Overview Tab
```
[Stat Cards: Total | Completed | Ongoing | Overdue | At Risk]
[Analytics Grid — 3 columns, compact]
  Row 1: [Status Donut]  [Health Distribution]  [Budget by Category]
  Row 2: [Progress Performance]  [Extension Summary]  [empty]
[Projects Table (max 10 rows)]
```

The analytics grid is compact (max ~300px tall) to keep the projects table visible without scrolling past it.

---

## Widget Details

### 1. Project Status Distribution (Donut Chart)
- **Type:** Doughnut (small, 180px height)
- **Data:** Ongoing / Completed / Planned / Suspended / Cancelled counts
- **Colors:** Blue (ongoing), Green (completed), Amber (planned), Red (suspended), Gray (cancelled)
- **Source:** `$ongoingCount`, `$completedCount`, `$plannedCount`, `$suspendedCount`, `$cancelledCount`
- `$plannedCount`, `$suspendedCount`, `$cancelledCount` — new queries needed

### 2. Health Distribution (Donut Chart)
- **Type:** Doughnut (small, 180px height)
- **Data:** On Track / At Risk / Overdue / No Data counts
- **Colors:** Green (on track), Amber (at risk), Red (overdue), Gray (no data)
- **Source:** `$atRiskCount`, `$overdueCount`. On Track = total ongoing - atRisk - overdue - noData. No Data = ongoing with no progress history.

### 3. Budget by Category (Horizontal Bar Chart)
- **Type:** Horizontal bar
- **Data:** Sum of `approved_budget` grouped by category name
- **Colors:** Gradient blues per bar
- **Source:** New query — `Project::where('is_archived', false)` joined with categories, grouped by category name, sum of approved_budget

### 4. Progress Performance (Grouped Bar Chart)
- **Type:** Grouped vertical bar (planned vs actual)
- **Data:** Top 8 ongoing projects by urgency — baseline planned % vs actual latest %
- **Colors:** Blue (planned), Green (actual if >= planned), Amber (actual if < planned)
- **Source:** `$currentMonthBaseline` + `$latestKnownProgress` (already computed, take top 8)

### 5. Extension Summary (Mini Stat Cards + Donut)
- **Type:** 3 mini stat cards (Total Granted / Total Days / Pending) + small donut showing approved vs pending
- **Data:** Approved count, sum of days_added, pending count
- **Source:** New query — `ProjectExtension` model grouped by status

---

## Implementation Plan

### Step 1: Controller Changes — Admin/Staff

**File:** `app/Http/Controllers/DashboardController.php`

Add after existing stat counts (around line 152):

```php
// ── ANALYTICS DATA ──────────────────────────────────────────
// Status distribution (for donut chart)
$plannedCount = (clone $baseQuery)->where('status', 'Planned')->count();
$suspendedCount = (clone $baseQuery)->where('status', 'Suspended')->count();
$cancelledCount = (clone $baseQuery)->where('status', 'Cancelled')->count();

// Budget by category (for horizontal bar chart)
$budgetByCategory = Project::where('is_archived', false)
    ->selectRaw('categories.name as category_name, SUM(approved_budget) as total_budget')
    ->join('categories', 'projects.category_id', '=', 'categories.id')
    ->groupBy('categories.name')
    ->pluck('total_budget', 'category_name');

// Extension summary (for mini stat cards + donut)
$extensionSummary = [
    'approved'   => \App\Models\ProjectExtension::where('status', 'approved')->count(),
    'pending'    => \App\Models\ProjectExtension::where('status', 'pending')->count(),
    'total_days' => \App\Models\ProjectExtension::where('status', 'approved')->sum('days_added'),
];
```

Add to `compact()`:
```php
'plannedCount', 'suspendedCount', 'cancelledCount',
'budgetByCategory', 'extensionSummary'
```

Remove `$recentActivities` from `compact()` (no longer needed).

### Step 2: Controller Changes — Contractor

**File:** `app/Http/Controllers/contractor/DashboardController.php`

Same analytics queries but scoped to `$projectIds` (contractor's projects only). Remove `$recentActivities` from `compact()`.

### Step 3: Blade Changes — Admin/Staff

**File:** `resources/views/admin/dashboard.blade.php`

- Remove lines 253-322 (activity feed section including `@forelse`, activity items, and closing `</div>`)
- Insert analytics grid with 5 `<canvas>` elements + Chart.js initialization
- Chart.js `DOMContentLoaded` block with all 5 chart configs
- Each chart uses Blade-injected data via `@json()` helpers

### Step 4: Blade Changes — Contractor

**File:** `resources/views/contractor/dashboard.blade.php`

- Remove lines 772-791 (activity section)
- Insert similar analytics grid (scoped to contractor's projects)

### Step 5: CSS

**File:** `public/css/dashboard/dashboard.css`

Add:
```css
.db-analytics-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}
.db-chart-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.db-chart-title {
    font-size: 13px;
    font-weight: 700;
    color: #374151;
    margin-bottom: 10px;
}
.db-ext-mini-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 10px;
}
.db-ext-mini-card {
    text-align: center;
    padding: 8px;
    background: #f9fafb;
    border-radius: 6px;
}
.db-ext-mini-num {
    font-size: 18px;
    font-weight: 700;
    color: #111827;
}
.db-ext-mini-label {
    font-size: 11px;
    color: #6b7280;
}
```

Responsive:
```css
@media (max-width: 1200px) {
    .db-analytics-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .db-analytics-grid { grid-template-columns: 1fr; }
}
```

---

## Files to Modify

| File | Changes |
|---|---|
| `app/Http/Controllers/DashboardController.php` | Add analytics queries, remove `$recentActivities` |
| `app/Http/Controllers/contractor/DashboardController.php` | Add analytics queries (contractor-scoped), remove `$recentActivities` |
| `resources/views/admin/dashboard.blade.php` | Remove activity feed (lines 253-322), add analytics grid + Chart.js init |
| `resources/views/contractor/dashboard.blade.php` | Remove activity section (lines 772-791), add analytics grid |
| `public/css/dashboard/dashboard.css` | Add `.db-analytics-grid`, `.db-chart-card`, responsive breakpoints |
| `textfiles/bugs_and_behavior_adjustments.md` | Log the adjustment |
| `textfiles/dashboard_analytics_plan.md` | This plan file |

---

## What Stays Unchanged

- Stat cards (5 cards)
- Projects table (max 10 rows)
- Alert bar pills
- Tab navigation (Overview, Calendar, Campus Map)
- Calendar and Campus Map tabs
- Dashboard filter AJAX (`public/js/dashboard.js`)
- Chart.js CDN link (already loaded in layout)

---

## Note

`resources/views/staff/dashboard.blade.php` is **dead code** — never rendered by any route. Can be cleaned up separately.

---

## Data Source Summary

| Widget | Already Computed? | New Query Needed? |
|---|---|---|
| Status Donut | Partial ($ongoingCount, $completedCount) | Yes ($plannedCount, $suspendedCount, $cancelledCount) |
| Health Donut | Partial ($atRiskCount, $overdueCount) | Derive On Track + No Data from total |
| Budget by Category | No | Yes (group by category) |
| Progress Performance | Yes ($currentMonthBaseline, $latestKnownProgress) | No — take top 8 |
| Extension Summary | No | Yes (ProjectExtension counts/sum) |

---

## Decision Log

- **Chart library:** Use Chart.js (already loaded in layout for S-curve). No new dependency.
- **Layout:** 3-column grid, compact height, projects table stays visible with minimal scrolling.
- **Contractor scope:** Same widgets but filtered to contractor's assigned projects only.
- **Staff dashboard file:** `resources/views/staff/dashboard.blade.php` is dead code — not touched in this plan.

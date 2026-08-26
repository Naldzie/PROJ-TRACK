# Contractor Performance Trends Plan

## Purpose

Add a time-series trend visualization to the existing Contractor Performance panel on the admin rating page. Shows whether a contractor's on-time delivery performance is improving, declining, or stable as they complete more projects.

**Current state:** Static snapshot — one aggregated set of numbers across all completed projects.
**After this feature:** Trend line showing how those numbers evolved project-by-project.

## Scope

### Option A: MVP (Retroactive Computation) — Selected

Compute running cumulative metrics on-the-fly from completed project history. No new database table. Plot a sparkline chart on the existing rating page.

### Option B: Production-Quality with Snapshot Table (Future Upgrade)

New `contractor_performance_snapshots` table stores timestamped snapshots each time a project completes or extension is approved. Cleaner, handles edge cases (extension retroactively changes on-time status), but requires migration + model + backfill seeder.

**If Option A proves insufficient** (e.g., extensions retroactively flip on-time status and the retroactive computation becomes fragile), upgrade to Option B:

```sql
CREATE TABLE contractor_performance_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contractor_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    completed_at DATE NOT NULL,
    on_time_pct DECIMAL(5,2) NOT NULL,
    avg_delay_days DECIMAL(8,2) NOT NULL,
    performance_rating TINYINT UNSIGNED NOT NULL,
    completed_count INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (contractor_id) REFERENCES contractors(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
```

Snapshot approach: After each `recompute()` call, append a row with the current aggregated metrics. The trend chart then simply reads snapshots ordered by `completed_at`. This avoids re-computing running aggregates and correctly handles retroactive extension changes.

---

## Option A Implementation

### Backend

**File: `app/Services/AI/ContractorPerformanceService.php`**

Add `getPerformanceTrend(Contractor $contractor): array`:

1. Query completed projects for this contractor where `actual_end_date` is not null, ordered by `actual_end_date ASC`.
2. For each project, compute whether it was on-time using the same `getEffectiveDeadline()` logic.
3. Build a running cumulative array:
   ```
   For each completed project (in chronological order):
     - running_completed_count++
     - if on_time: running_on_time_count++
     - running_on_time_pct = (running_on_time_count / running_completed_count) * 100
     - update running_total_delay / running_late_count
     - running_avg_delay = running_late_count > 0 ? running_total_delay / running_late_count : 0
     - append { date: actual_end_date, on_time_pct: running_on_time_pct, avg_delay: running_avg_delay, count: running_completed_count }
   ```
4. Return the array (empty if no completed projects).

**File: `app/Http/Controllers/Admin/RatingController.php`**

In `index()`, for each contractor in the `contractorsJson` payload, include trend data:
```php
'trend' => $performanceService->getPerformanceTrend($contractor)
```

### Frontend

**File: `resources/views/admin/rating.blade.php`**

1. Add Chart.js CDN script tag (if not already loaded).
2. Below the existing `#perfBody` stats grid, add a `<canvas id="perfTrendChart">` inside a new section.
3. Add `renderTrendChart(trendData)` JS function:
   - Small sparkline line chart (width: 100%, height: ~140px)
   - X-axis: project completion dates (formatted as "MMM YYYY")
   - Y-axis: running on-time delivery % (0-100)
   - Line color: `#4f6df5` (blue, matches existing theme)
   - Fill: gradient under the line
   - Point radius: 4px for each data point
   - No legend, minimal gridlines
4. Add a subtitle below the chart:
   - If slope > 0: "↑ Improving (+X% over N projects)" in green
   - If slope < -2: "↓ Declining (−X% over N projects)" in red
   - Otherwise: "→ Stable" in gray
5. Call `renderTrendChart()` when contractor is selected (alongside existing `renderPerfPanel()`).

### Styling

- Chart container: `border-radius: 8px`, `background: #f8fafc`, `padding: 12px`, `margin-top: 12px`
- Chart title: "Performance Trend" with `font-size: 12px`, `color: #6b7280`
- Trend indicator: `font-size: 11px`, bold, colored by direction

### Edge Cases

- 0 completed projects: Hide trend section entirely.
- 1 completed project: Show single dot, label "1 project completed" (no trend to display).
- 2+ projects: Show line chart with trend indicator.

### Tests

- `ContractorPerformanceServiceTest::test_get_performance_trend_empty_when_no_completed_projects()`
- `ContractorPerformanceServiceTest::test_get_performance_trend_single_project()`
- `ContractorPerformanceServiceTest::test_get_performance_trend_multiple_projects()`
- `ContractorPerformanceServiceTest::test_get_performance_trend_running_cumulative_accuracy()`
- `ContractorPerformanceServiceTest::test_get_performance_trend_includes_extension_adjusted_deadlines()`

### Files Changed

| File | Change |
|------|--------|
| `app/Services/AI/ContractorPerformanceService.php` | Add `getPerformanceTrend()` method |
| `app/Http/Controllers/Admin/RatingController.php` | Pass trend data to view |
| `resources/views/admin/rating.blade.php` | Chart.js CDN + canvas + render function |
| `tests/Unit/Services/AI/ContractorPerformanceServiceTest.php` | Trend tests |

### Testing Checklist

- [ ] Admin rating page loads without errors
- [ ] Trend chart renders for contractor with 2+ completed projects
- [ ] Trend chart hidden for contractor with 0-1 completed projects
- [ ] Running on-time % is accurate at each data point
- [ ] Trend indicator shows correct direction (improving/declining/stable)
- [ ] Chart matches existing design system (colors, spacing)
- [ ] Chart is responsive (scales with panel width)
- [ ] All existing tests still pass

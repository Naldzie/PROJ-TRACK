# Phase 9 — Contractor Performance Tracking

CSPC PMU System — AI Module V2

Status: ✅ DONE
Branch: `ai/phase9-contractor-performance` (from develop)
Date: August 25, 2026
Plan file: textfiles/contractor_performance_tracking_plan.md
Commits: 6a8ad13 (plan files), 7259129 (implementation)
Tests: 16/16 passing (12 unit + 4 integration), full suite 753/755 (2 pre-existing EmailDraft failures)

Reference:
- textfiles/active_reference/ai_module_ver2_master_plan.txt §Phase 9
- textfiles/active_reference/AI_MODULE_HANDOVER.md §4
- textfiles/ai_recommendation_data_gaps.txt (Phase 10 prerequisite)
- textfiles/cross_project_industry_research.md (industry benchmark data)

---

## 1. OBJECTIVE

Compute and store **aggregated contractor performance metrics** from completed
project history. This is a prerequisite for Phase 10 (AI-Powered Recommendations)
and enables data-driven contractor evaluation beyond the existing per-project
star rating.

**Key distinction from existing system:**
- Existing `contractor_ratings`: per-project, 1-5 stars, based on AI regression
  variance (how far off the S-curve was). Answers: "How did this contractor do
  on THIS project's plan?"
- Phase 9 performance metrics: aggregated across all completed projects, based
  on calendar-based on-time delivery. Answers: "How reliable is this contractor
  overall?"

Both systems coexist. The star rating is project-specific; performance metrics
are contractor-level aggregates.

---

## 2. CURRENT STATE (verified Aug 25, 2026)

### What Exists

| Component | File | Status |
|-----------|------|--------|
| `contractors` table | `database/migrations/2026_02_28_222119_create_contractors_table.php` + `2026_04_14_080858_add_missing_fields_to_contractors_table.php` | Basic info only — zero performance fields |
| `Contractor` model | `app/Models/Contractor.php` | fillable: user_id, contractor_id, company_name, contractor_name, contact_number, company_landline, company_address. Relationships: user(), projects() |
| `contractor_ratings` table | `database/migrations/2026_07_30_035145_create_contractor_ratings_table.php` | Per-project 1-5 star rating (AI regression-based) |
| `ContractorRating` model | `app/Models/ContractorRating.php` | Relationships: contractor(), project(), aiSolution(), createdBy() |
| `RatingController` | `app/Http/Controllers/Admin/RatingController.php` | index, suggest, store, autoRateAll + computeRating + generateReview |
| Rating UI | `resources/views/admin/rating.blade.php` | 3-panel workspace: contractor list → projects → analysis |
| `Project::getCompletionDelay()` | `app/Models/Project.php` | Returns delay in days (positive = late, negative = early) for Completed projects |
| `Project::getCompletionStatusLabel()` | `app/Models/Project.php` | Returns 'On Time' / 'Overdue' / 'Early' |
| Auto-completion | `app/Http/Controllers/ProjectProgressController.php` | `autoCompleteOnFullProgress()` sets status='Completed' + actual_end_date when progress hits 100% |

### Contractors Table Schema (current)

| Column | Type |
|--------|------|
| id | bigint PK |
| user_id | bigint FK → users |
| contractor_id | string, nullable |
| company_name | string, nullable |
| contractor_name | string, nullable |
| contact_number | string, nullable |
| company_landline | string, nullable |
| company_address | string, nullable |
| created_at | timestamp |
| updated_at | timestamp |

### Projects Table — Key Fields for Performance

| Field | Purpose |
|-------|---------|
| contractor_id | FK → contractors |
| status | 'Planned', 'Ongoing', 'Completed', 'Suspended', 'Cancelled' |
| original_planned_end_date | Frozen at creation — never modified by extensions |
| planned_end_date | Mutable — updated when extensions are approved |
| actual_end_date | Set on auto-complete or manual completion |
| extension_count | Number of approved extensions |

### Extension System — Key for On-Time Calculation

| Field | Purpose |
|-------|---------|
| original_end_date | Deadline before this extension |
| new_end_date | Requested extended deadline |
| approved_new_end_date | Actually granted date |
| status | 'pending', 'approved', 'rejected', 'superseded' |

---

## 3. INDUSTRY ALIGNMENT

### On-Time Delivery Calculation (Industry Standard)

Per AACE RP 109R-19, FAR 52.211-10, and FIDIC Sub-Clause 8.4:
the **extension-adjusted deadline** is the reference for on-time assessment,
NOT the original contract date.

```
effective_deadline = original_planned_end_date + sum(approved_extensions)
is_on_time = actual_end_date <= effective_deadline
delay_days = actual_end_date - effective_deadline (or 0 if on time)
```

**Why extension-adjusted:** When an extension is approved, the extended date
becomes the new contractual deadline. The contractor should not be penalized
for time they were formally granted.

### Performance Rating Scale (5-Point Industry Standard)

Used by USACE/CPARS, PMWeb, Procore, and most construction PM systems:

| Rating | Label | On-Time % | Avg Delay |
|--------|-------|-----------|-----------|
| 5 | Superior | >= 90% | <= 7 days |
| 4 | Good | >= 80% | <= 15 days |
| 3 | Satisfactory | >= 70% | <= 30 days |
| 2 | Below Average | >= 50% | <= 60 days |
| 1 | Poor | < 50% | > 60 days |

---

## 4. IMPLEMENTATION

### Step 1: Migration — Add Performance Fields to Contractors

**New file:** `database/migrations/2026_08_25_000001_add_performance_fields_to_contractors_table.php`

```php
Schema::table('contractors', function (Blueprint $table) {
    $table->decimal('performance_rating', 3, 2)->nullable()->after('company_address');
    $table->decimal('on_time_delivery_pct', 5, 2)->nullable()->after('performance_rating');
    $table->unsignedInteger('completed_projects_count')->default(0)->after('on_time_delivery_pct');
    $table->decimal('avg_delay_days', 10, 2)->nullable()->after('completed_projects_count');
    $table->timestamp('last_project_completed_at')->nullable()->after('avg_delay_days');
});
```

**Columns:**

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `performance_rating` | DECIMAL(3,2), nullable | null | Computed 1.00-5.00 rating |
| `on_time_delivery_pct` | DECIMAL(5,2), nullable | null | % of completed projects on time |
| `completed_projects_count` | INT UNSIGNED | 0 | Number of completed projects |
| `avg_delay_days` | DECIMAL(10,2), nullable | null | Average delay (positive = late) |
| `last_project_completed_at` | TIMESTAMP, nullable | null | When most recent project completed |

**Rationale:** Nullable columns because contractors with 0 completed projects
have no metrics to compute. The `completed_projects_count` defaults to 0 because
it's a simple count.

### Step 2: ContractorPerformanceService

**New file:** `app/Services/AI/ContractorPerformanceService.php`

```php
class ContractorPerformanceService
{
    /**
     * Recompute all performance metrics for a contractor from their
     * completed project history.
     *
     * Called when:
     * - A project status changes to "Completed" (auto-complete or manual)
     * - On demand via AJAX for the contractor detail view
     * - Bulk recompute via admin command
     */
    public function recompute(Contractor $contractor): array

    /**
     * Get performance summary for display (without recomputing).
     */
    public function getPerformanceSummary(Contractor $contractor): array

    /**
     * Compute the performance rating (1-5) from metrics.
     */
    protected function computeRating(float $onTimePct, float $avgDelayDays): int

    /**
     * Recompute metrics for ALL contractors (bulk operation).
     */
    public function recomputeAll(): void
}
```

#### `recompute()` Algorithm

```
1. Get all projects where contractor_id = $contractor->id AND status = 'Completed'
2. If no completed projects: set all metrics to null/0, return
3. For each completed project:
   a. effective_deadline = original_planned_end_date
   b. Get approved extensions for this project (status = 'approved')
   c. For each approved extension: effective_deadline = approved_new_end_date
      (the latest approved extension wins — it replaces the previous deadline)
   d. delay_days = actual_end_date->diffInDays(effective_deadline)
      (positive = late, negative = early, 0 = on time)
   e. is_on_time = actual_end_date <= effective_deadline
4. Aggregate:
   on_time_count = count(projects where is_on_time)
   total = count(all completed projects)
   on_time_pct = (on_time_count / total) * 100
   avg_delay_days = sum(delay_days) / total
   performance_rating = computeRating(on_time_pct, avg_delay_days)
   last_project_completed_at = max(actual_end_date across all)
5. Save to contractors table
6. Return computed metrics
```

#### `computeRating()` Algorithm

```
if onTimePct >= 90 AND avgDelayDays <= 7:
    return 5  // Superior
if onTimePct >= 80 AND avgDelayDays <= 15:
    return 4  // Good
if onTimePct >= 70 AND avgDelayDays <= 30:
    return 3  // Satisfactory
if onTimePct >= 50 AND avgDelayDays <= 60:
    return 2  // Below Average
return 1  // Poor
```

**Design decision:** Uses AND (both conditions must be met) rather than OR.
A contractor with 95% on-time but 20-day avg delay when late is rated 4, not 5.
This aligns with CPARS methodology where both timeliness and magnitude matter.

#### Extension Resolution Logic

```
For each project:
  extensions = project->extensions()->where('status', 'approved')->get()
  if extensions->isEmpty():
    effective_deadline = project->original_planned_end_date
  else:
    effective_deadline = extensions->last()->approved_new_end_date
    // The latest approved extension replaces all previous deadlines
```

**Why `last()` not `max()`:** Extensions are sequential (extension_number 1, 2, 3).
Each approved extension supersedes the previous deadline. The latest approved
extension's `approved_new_end_date` IS the current effective deadline.

### Step 3: Auto-Update Hooks

#### Hook 1: Project Auto-Completion

**File:** `app/Http/Controllers/ProjectProgressController.php`
**Method:** `autoCompleteOnFullProgress()` (called when progress hits 100%)

After the existing auto-completion logic (setting status + actual_end_date),
add:

```php
// Phase 9: recompute contractor performance metrics
if ($project->contractor) {
    app(ContractorPerformanceService::class)->recompute($project->contractor);
}
```

#### Hook 2: Extension Approval

**File:** `app/Http/Controllers/Admin/ProjectExtensionController.php`
**Method:** `recomputeProjectDeadline()` (called when extension is approved)

After the deadline is updated, recompute the contractor's metrics because
the on-time calculation may have changed:

```php
// Phase 9: recompute contractor performance (deadline changed)
if ($project->contractor) {
    app(ContractorPerformanceService::class)->recompute($project->contractor);
}
```

#### Hook 3: Manual Project Completion

If a project is manually set to 'Completed' via admin update (not via
auto-complete), the same recompute should fire. This happens in
`ProjectController::update()` or similar — check for status change to
'Completed' and trigger recompute.

### Step 4: RecommendationEngine Integration

**File:** `app/Services/AI/RecommendationEngine.php`

#### 4a. Add contractor performance to `extractFactors()`

```php
// Phase 9: contractor performance metrics
$factors['contractor_performance_rating'] = $prediction['contractor_performance_rating'] ?? null;
$factors['contractor_on_time_pct'] = $prediction['contractor_on_time_pct'] ?? null;
$factors['contractor_avg_delay'] = $prediction['contractor_avg_delay'] ?? null;
```

#### 4b. Add corrective rule for poor performers

In `assessCorrective()` or a new method:

```php
// Phase 9: contractor with history of delays + open issues
if ($factors['contractor_performance_rating'] !== null
    && $factors['contractor_performance_rating'] <= 2
    && $factors['open_issue_count'] >= 2) {
    $recommendations[] = [
        'message' => "Contractor has a history of delays (rating {$factors['contractor_performance_rating']}/5, avg {$factors['contractor_avg_delay']} days late) with {$factors['open_issue_count']} open issues — consider closer schedule monitoring",
        'category' => 'corrective',
        'reason' => 'Contractor performance history indicates elevated risk',
        'priority' => 4,
    ];
}
```

#### 4c. Pass contractor performance through AIPredictionService

In `AIPredictionService::generatePrediction()`, after building the prediction
report, add contractor performance data if available:

```php
// Phase 9: inject contractor performance into prediction for RecommendationEngine
if ($project->contractor) {
    $perfService = app(ContractorPerformanceService::class);
    $perf = $perfService->getPerformanceSummary($project->contractor);
    $prediction['contractor_performance_rating'] = $perf['performance_rating'];
    $prediction['contractor_on_time_pct'] = $perf['on_time_delivery_pct'];
    $prediction['contractor_avg_delay'] = $perf['avg_delay_days'];
}
```

### Step 5: UI Enhancements

**File:** `resources/views/admin/rating.blade.php`

#### 5a. Contractor List Cards — Add Performance Metrics

In the left panel contractor cards, add below the star rating:

```html
<div class="contractor-performance">
    <span class="perf-badge">{{ $contractor->performance_rating ?? 'N/A' }}</span>
    <span class="perf-detail">
        {{ $contractor->on_time_delivery_pct ?? '--' }}% on-time
        · {{ $contractor->completed_projects_count }} completed
    </span>
</div>
```

#### 5b. Sort by Performance

Add sort options to the contractor list: by rating (existing), by on-time %,
by avg delay.

#### 5c. Performance Badge Colors

| Rating | Badge Color |
|--------|-------------|
| 5 | Green |
| 4 | Emerald |
| 3 | Amber |
| 2 | Orange |
| 1 | Red |
| N/A | Gray |

### Step 6: Controller — On-Demand Recompute Endpoint

**File:** `app/Http/Controllers/Admin/RatingController.php`

Add a new endpoint for on-demand recompute:

```php
public function recomputePerformance(Request $request, Contractor $contractor)
{
    $service = app(ContractorPerformanceService::class);
    $metrics = $service->recompute($contractor);

    return response()->json([
        'success' => true,
        'metrics' => $metrics,
    ]);
}
```

**Route:** `POST /admin/rating/{contractor}/recompute-performance`

This enables the UI to trigger a recompute on demand (e.g., after viewing
a contractor's profile).

### Step 7: Tests

**New file:** `tests/Unit/Services/AI/ContractorPerformanceServiceTest.php`

| Test | What It Verifies |
|------|------------------|
| `test_recompute_with_no_completed_projects` | All metrics null/0 when no completed projects |
| `test_recompute_with_one_on_time_project` | on_time_pct=100, avg_delay=0, rating=5 |
| `test_recompute_with_one_late_project` | on_time_pct=0, avg_delay=positive, rating=1-2 |
| `test_recompute_with_mixed_projects` | Correct aggregation across on-time and late |
| `test_extension_adjusted_deadline` | Approved extension moves effective deadline |
| `test_superseded_extension_not_counted` | Only latest approved extension counts |
| `test_rating_algorithm_boundaries` | Exact boundary conditions for each rating tier |
| `test_recompute_saves_to_contractor` | Metrics persisted to contractors table |
| `test_recompute_all_bulk` | Bulk recompute updates all contractors |

**Integration test:** `tests/Feature/Services/AI/ContractorPerformanceIntegrationTest.php`

| Test | What It Verifies |
|------|------------------|
| `test_auto_complete_triggers_recompute` | Auto-complete at 100% triggers contractor recompute |
| `test_extension_approval_triggers_recompute` | Extension approval triggers contractor recompute |
| `test_recommendation_engine_uses_performance` | Poor contractor rating generates corrective rec |

---

## 5. FILES SUMMARY

### New Files

| File | Purpose |
|------|---------|
| `database/migrations/2026_08_25_000001_add_performance_fields_to_contractors_table.php` | Add 5 performance columns to contractors |
| `app/Services/AI/ContractorPerformanceService.php` | Core computation engine |
| `tests/Unit/Services/AI/ContractorPerformanceServiceTest.php` | Unit tests |
| `tests/Feature/Services/AI/ContractorPerformanceIntegrationTest.php` | Integration tests |

### Modified Files

| File | Change |
|------|--------|
| `app/Models/Contractor.php` | Add performance fields to `$fillable` |
| `app/Http/Controllers/ProjectProgressController.php` | Add recompute hook in `autoCompleteOnFullProgress()` |
| `app/Http/Controllers/Admin/ProjectExtensionController.php` | Add recompute hook in `recomputeProjectDeadline()` |
| `app/Services/AI/AIPredictionService.php` | Pass contractor performance into prediction report |
| `app/Services/AI/RecommendationEngine.php` | Add contractor_performance factor + corrective rule |
| `app/Http/Controllers/Admin/RatingController.php` | Add recompute endpoint |
| `resources/views/admin/rating.blade.php` | Show performance metrics in contractor cards |
| `routes/web.php` | Add recompute-performance route |

### Files NOT Changed

| File | Why |
|------|-----|
| `app/Models/Project.php` | `getCompletionDelay()` and `getCompletionStatusLabel()` already exist |
| `app/Models/ContractorRating.php` | Existing star rating system is independent |
| `app/Services/AI/AIExplanationService.php` | No cross-project contractor data in prompt yet (Phase 10) |

---

## 6. OUT OF SCOPE (deferred to Phase 10)

- No AI-generated contractor performance narrative (that's Phase 10)
- No contractor-facing performance dashboard (contractors are read-only by design)
- No trend visualization over time (requires multiple data points per contractor)
- No integration with Gemini prompt (Phase 10 will add contractor context)

---

## 6A. EXTENSION STATS (Informational — Added Post-Phase 9)

### Context

Users requested visibility into extension frequency and total extension days
to understand contractor patterns. Research showed most government DOT systems
do NOT penalize for extension count (extensions are contractual mechanisms
for legitimate delays). Decision: display extension stats as informational
metrics with no scoring impact.

### What Was Added

| Field | Type | Purpose |
|-------|------|---------|
| `total_extensions_count` | unsigned int | Count of approved extensions across all completed projects |
| `total_extension_days` | decimal(10,2) | Sum of days from approved extensions on completed projects |

These are displayed as **averages per completed project** in the UI
(avg extensions/project, avg extension days/project) for fair comparison
across contractors with different project counts.

### Panel Layout (9 metrics + hero badge)

| Row | Left | Right |
|-----|------|-------|
| Hero | Rating badge + label | — |
| 1 | On-Time Delivery | Avg Delay |
| 2 | Completed Projects | Ongoing Projects |
| 3 | Total Projects | Last Completed |
| 4 | Avg Extensions/Project | Avg Extension Days/Project |

### Design Decisions

- **Per-project averages, not totals**: Totals are misleading when comparing
  contractors with different project counts (12 extensions on 20 projects ≠ 12 on 3)
- **Only approved extensions counted**: Rejected/pending extensions excluded
- **No scoring impact**: Extension stats are informational only; rating stays
  based on on-time % + avg delay
- **Avg days per project, not per extension**: PMs care about total time
  consumed, not individual extension length

### Files Changed

| File | Change |
|------|--------|
| `database/migrations/2026_08_25_000002_add_extension_info_to_contractors_table.php` | NEW: migration |
| `app/Models/Contractor.php` | Add `total_extensions_count`, `total_extension_days` to `$fillable` |
| `app/Services/AI/ContractorPerformanceService.php` | Compute extension stats in `recompute()` + include in summary |
| `app/Http/Controllers/Admin/RatingController.php` | Add new fields to JSON payload |
| `resources/views/admin/rating.blade.php` | 9-metric layout with ongoing + total + extension averages |
| `tests/Unit/Services/AI/ContractorPerformanceServiceTest.php` | 5 new tests for extension stats |

---

## 7. BRANCH & PROCESS

| Item | Value |
|------|-------|
| Branch | `ai/phase9-contractor-performance` (from develop) |
| Base | `develop` @ `10ff312` |
| Rule 8 | Test each step before proceeding |
| Rule 10 | Inform user after phase completes |
| Rule 11 | Update this plan + progress log each step |
| Rule 12 | Do NOT merge to main without approval |

---

## 8. DECISION LOG

1. **Extension-adjusted deadline for on-time calculation**
   Context: The original plan didn't address how extensions affect on-time %.
   Decision: Use effective_deadline = original + latest approved extension.
   Consequences: Fair contractor assessment; aligns with AACE/FIDIC standards.

2. **Both star rating and performance metrics coexist**
   Context: Existing contractor_ratings provides per-project regression-based
   stars. Phase 9 adds aggregated deadline-based metrics.
   Decision: Keep both systems. Stars answer "how did this project go?"
   Performance metrics answer "how reliable is this contractor overall?"

3. **Rating algorithm uses AND (not OR) for thresholds**
   Context: A contractor with 95% on-time but 20-day avg delay when late.
   Decision: Both on-time % AND avg delay must meet the threshold.
   Consequences: More conservative rating; prevents hiding large delays
   behind a high on-time percentage.

4. **Hook points: auto-complete + extension approval**
   Context: Performance metrics should stay current without manual triggers.
   Decision: Auto-recompute when project completes or extension is approved.
   Consequences: Metrics are always up-to-date; no batch job needed.

5. **Nullable columns for contractors with no completed projects**
   Context: New contractors or those with only ongoing projects have no data.
   Decision: All performance columns are nullable (except completed_projects_count=0).
   Consequences: UI shows "N/A" for contractors with no completed projects.

6. **Extension stats are informational only (no scoring impact)**
   Context: User asked whether extension frequency/days should affect rating.
   Research: Government DOT systems (SCDOT, MnDOT, FDOT, MTO) do NOT penalize
   for extension count. Extensions are contractual mechanisms for legitimate delays.
   Decision: Display extension stats as informational metrics only.
   Consequences: PMs can see patterns; rating remains purely on-time + delay based.

7. **Per-project averages, not totals for extension metrics**
   Context: Totals are misleading when comparing contractors with different
   project counts (12 extensions on 20 projects ≠ 12 on 3 projects).
   Decision: Show avg extensions/project and avg extension days/project.
   Consequences: Fair comparison across contractors regardless of project count.

---

END OF PLAN

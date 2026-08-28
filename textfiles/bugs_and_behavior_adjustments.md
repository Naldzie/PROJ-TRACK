# Bugs & Behavior Adjustments

**Date:** August 28, 2026
**Status:** ALL FIXES IMPLEMENTED (August 28, 2026)

---

## Bug 1: Notification Bell Dropdown Shows Wrong Label for Risk Level Changes

**Status: FIXED** — bell.blade.php, NotificationController::humanType(), NotificationController::title() all updated.

### Problem

The notification bell dropdown (topbar collapsible container) shows "Issue reported" for risk level change notifications instead of the correct label. The full notification list page (`_list.blade.php`) already handles this correctly — only the bell dropdown is affected.

### Root Cause

`resources/views/notifications/partials/bell.blade.php` (lines 60-68) uses a nested ternary to map notification types to display labels. `RiskLevelChanged` has no branch, so it falls through to the default: `'Issue reported'`.

```php
{{ $n->type === \App\Notifications\DeadlineReminder::class
    ? ($d['bucket'] === 'overdue'
        ? 'Project overdue'
        : 'Deadline in ' . ($d['days_remaining'] ?? 0) . ' days')
    : ($n->type === \App\Notifications\OverdueInspectionReminder::class
        ? 'Inspection overdue'
        : ($n->type === \App\Notifications\ExtensionStatusChanged::class
            ? 'Extension ' . ucfirst($d['status'] ?? '')
            : 'Issue reported')) }}  ← fallback catches RiskLevelChanged
```

### Affected Locations

| File | Lines | Issue |
|---|---|---|
| `resources/views/notifications/partials/bell.blade.php` | 60-68 | Missing `RiskLevelChanged` branch in ternary |
| `app/Http/Controllers/NotificationController.php` | 93-102 (`humanType()`) | Missing `RiskLevelChanged` case in match |
| `app/Http/Controllers/NotificationController.php` | 105-118 (`title()`) | Missing `RiskLevelChanged` case in match |

### Fix

Add `RiskLevelChanged` branches to all three locations. Reference label from `_list.blade.php` line 94-95:

```
"Risk level changed — {project_title} ({previous_level} → {current_level})"
```

### Effort

Low — 3 files, ~10 lines added.

---

## Bug 2: AI Prediction Tab Shows "On Track" While Dashboard/Index Shows "At Risk" for Same Project

**Status: FIXED (Phase 1)** — AIPredictionPresenter now calls determine() with escalation context. Escalation Factors section added to AI Prediction Blade. Dashboard/Index health badges show escalation info icon.

**Status: Escalation Toggle (Phase 2) — PLANNED.** Escalation will be made configurable via `config('ai.health.escalation_enabled')`. Default OFF. Rationale: reduce complexity for panel; escalation logic needs further refinement before production use. See "Escalation Toggle Plan" section below.

### Problem

For a project with 3% variance (e.g., planned 10%, actual 7%):
- AI Prediction tab shows **"ON TRACK"** (green)
- Project index page and dashboard show **"AT RISK"** (orange)

Both surfaces display data for the same project at the same time, giving contradictory health signals.

### Root Cause

Two different health classification functions are used:

| Surface | Function Called | Applies Escalation? |
|---|---|---|
| AI Prediction tab | `ProjectHealthService::classifyGap($progressGap)` | **No** — pure gap only |
| Dashboard / Project index | `ProjectHealthService::determine($project, $context)` | **Yes** — gap + record-based escalation |

Both functions use identical gap thresholds from `config('ai.risk.gap')`:
- `ahead <= -5`, `on_track <= 5`, `low < 10`, `medium < 15`, `high >= 15`

For 3% gap: both return `'on_track'` from the gap check. But `determine()` then applies **escalation factors** (lines 210-218 of `ProjectHealthService.php`):

```php
if ($level === 'on_track') {
    $reason = $this->escalationReason($context);
    if ($reason !== null) {
        $level = 'at_risk';  // bumped from on_track
    }
}
```

Escalation triggers if the project has **any** of:
1. Open High/Critical-severity issue
2. Unresolved issue aging > 30 days
3. Open high-priority risk (score ≥ 7)
4. Pending extension request

The AI tab calls `classifyGap()` directly at `AIPredictionPresenter.php` line 504 — no escalation logic is applied.

### Code Paths

**AI Prediction tab** (`AIPredictionPresenter::presentNormal()`):
```php
$currentGapRisk = $healthService->classifyGap($progressGap);   // line 504
$overallRisk = $currentGapRisk;                                  // line 506
$statusDisplay = in_array($overallRisk, ['ahead', 'on_track']) ? 'ON TRACK' : 'AT RISK';  // line 517
```

**Dashboard** (`dashboard-table-rows.blade.php` lines 51-60):
```php
$health = app(ProjectHealthService::class)->determine($project, [
    ...
    'open_high_issues'  => ...,
    'aging_issue_count' => ...,
    'open_high_risks'   => ...,
    'pending_extension' => ...,
]);
```

**Project index** (`ProjectController::buildHealthMap()` line 256):
```php
$health = $service->determine($project, $this->healthContext($project));
```

### Fix Options

#### Option A: AI tab also calls `determine()` (Recommended)

Change `AIPredictionPresenter::presentNormal()` to call `$healthService->determine($project, $context)` instead of `classifyGap($progressGap)`.

| Pros | Cons |
|---|---|
| All surfaces show the same health badge — consistent UX | AI tab would need to receive escalation context (issues, risks, extensions) |
| Escalation factors are meaningful signals — users should see them everywhere | Slightly more data passed to the AI presenter |
| Single source of truth for project health | Changes the AI tab's "ON TRACK" header to "AT RISK" when escalated |
| Simple change — ~10 lines in presenter | |

**Impact on AI tab display:** The header badge would change from "ON TRACK" to "AT RISK" when escalation factors are present, even if the raw gap is small. This is the correct behavior — a project with a critical open issue IS at risk regardless of current gap.

#### Option B: Remove escalation from `determine()`

Make `determine()` use pure gap-based classification only (same as `classifyGap()`).

| Pros | Cons |
|---|---|
| All surfaces show the same health badge — consistent UX | Loses meaningful escalation signal everywhere |
| Simplest change — remove ~15 lines from `determine()` | A project with a critical issue and 3% gap shows "ON TRACK" — misleading |
| No changes to the AI tab | Dashboard no longer warns about issue-driven risk |

**Impact on dashboard/index:** All projects with gap ≤ 5% would show "On Track" regardless of open issues, aging issues, high risks, or pending extensions. This weakens the dashboard's risk visibility.

#### Option C: Keep current split, add escalation factors to AI tab

Keep `determine()` with escalation for dashboard/index. Add escalation indicator to the AI tab's Project Health Overview section (without changing the ON TRACK / AT RISK badge).

| Pros | Cons |
|---|---|
| AI tab shows more information without changing the badge | Two surfaces still show different badges — inconsistency remains |
| Users can see escalation factors in the AI detail view | More complex — new UI elements in AI tab |
| Dashboard stays aggressive with escalation | Users may be confused by contradictory badges |

**Impact:** The AI tab would show "ON TRACK" in the header but list escalation factors (open issues, aging issues, etc.) in the Project Health Overview table. The dashboard would still show "AT RISK".

#### Option D: Escalation as a separate badge/tag

Add a separate "ESCALATED" tag or indicator alongside the health badge, visible on all surfaces.

| Pros | Cons |
|---|---|
| Preserves the pure gap-based health signal | More UI complexity — new badge type |
| Adds escalation visibility without replacing the gap signal | Users need to understand two separate indicators |
| Could be applied consistently across all surfaces | Still inconsistent if only some surfaces show it |

**Impact:** A project with 3% gap and an open critical issue would show "ON TRACK + ESCALATED" everywhere. The gap signal and the escalation signal are separated.

### Recommendation

**Option A** is the cleanest approach — it ensures all surfaces agree on project health, which is what users expect. The escalation factors are real risk signals that should be visible in the AI prediction tab just as they are on the dashboard. The AI tab already shows detailed health information (progress gap, schedule status, forecast risk, etc.) — adding escalation is consistent with its purpose as a comprehensive health view.

### Effort

| Option | Files Changed | Lines | Complexity |
|---|---|---|---|
| A | `AIPredictionPresenter.php`, possibly `AIPredictionService.php` | ~15-20 | Low — pass context to presenter |
| B | `ProjectHealthService.php` | ~15 removed | Very low — delete escalation code |
| C | `AIPredictionPresenter.php`, `ai-prediction.blade.php` | ~30-40 | Medium — new UI elements |
| D | Multiple Blade files + health service | ~50+ | High — new badge system |

---

## Bug 3: Risk Level Notification Only Triggers on Manual Progress Recording

**Status: FIXED (Phase 1)** — sendRiskLevelChangeNotifications() method added to SendNotifications command. Detects risk-level changes at month boundaries when time advances past a baseline month with no recorded progress.

**Status: Grace Period Fix (Phase 2) — PLANNED.** The current implementation uses a7-day grace period (inherited from inspection logic) which delays the risk notification incorrectly. See "Risk Notification Grace Period Fix" section below.

### Problem

Risk level change notifications only fire when a user manually records monthly progress. When a new month starts and the gap naturally widens (because `expectedProgress()` picks up the new month's baseline while actual progress stays at the previous month's value), no notification fires until the user records progress.

**Example:**
- June: planned 10%, actual 7% → gap 3% (On Track) → `previous_risk_level = "On Track"`
- July 1: planned 18%, actual still 7% → gap 11% (Medium Risk)
- **No notification fires** until user records July's progress

### Root Cause

`RiskLevelChanged` is dispatched **solely** inside `ProjectProgressController::recordMonthlyProgress()` (lines 465-519). There is no scheduled task, observer, event listener, or any other mechanism that detects risk level changes at month boundaries.

| Mechanism | Monitors Risk Level? |
|---|---|
| `recordMonthlyProgress()` | **Yes** — only place |
| `notifications:scan` (daily 07:00) | **No** — only deadline + inspection reminders |
| `reports:send-ai-monthly` (monthly 01st 08:00) | **No** — only AI report emails |
| Model observers | **None exist** |
| Event listeners | **None for risk level** |

### Fix

Add risk-level-change detection to the existing `notifications:scan` command (`app/Console/Commands/SendNotifications.php`). This command already:
- Runs daily at 07:00 via `routes/console.php`
- Iterates all ongoing projects
- Handles deadline reminders and overdue inspection alerts

**Logic to add:**

For each ongoing project in the existing `$projects` loop:
1. Compute live gap: `ProjectHealthService::expectedProgress($project)` minus latest recorded actual progress
2. Classify with `ProjectHealthService::classifyGap($gap)` → map to display label
3. Compare with `$project->previous_risk_level`
4. If different → dispatch `RiskLevelChanged` notification to admins + assigned staff + contractor
5. Update `$project->previous_risk_level`

**Deduplication is already handled:**
- `RiskLevelChanged::toDatabase()` generates key: `risk_level_change:{project_id}:{currentLevel}:{monthLabel}`
- `NotificationService::notifyUnique()` checks for existing notification with same type + key
- A daily scan will not re-fire for an already-notified transition

**Date context:**
- The scan runs in CLI context (no session), so `DateHelper::now()` returns real time
- On July 1, `expectedProgress()` picks up July's baseline → gap widens → notification fires
- Consistent with how deadline/inspection reminders already work per AGENTS.md

### Design Considerations

1. **First scan after deployment:** Projects with `previous_risk_level = null` will need backfill (same logic as `recordMonthlyProgress` lines 476-483). Can derive from the latest progress record.

2. **Manual recording after scan:** If the scan fires a notification and then the user records progress on the same day, `recordMonthlyProgress` will compare against the updated `previous_risk_level` (set by the scan). If the risk level is the same, no duplicate notification fires. If it changed further, a second notification fires — correct behavior.

3. **No progress recorded at all:** If a project has zero progress history, `expectedProgress()` returns a baseline value and actual is 0. The gap is classified correctly. `previous_risk_level` being null triggers the backfill path.

### Effort

Medium — modify one existing command, add ~30-40 lines. No new files needed.

---

## Summary

| # | Bug | Severity | Fix Effort | Status |
|---|---|---|---|---|
| 1 | Bell dropdown wrong label | Low (cosmetic) | Low | FIXED |
| 2 | AI tab vs dashboard health inconsistency | Medium (UX confusion) | Low-Medium | FIXED + Toggle PLANNED |
| 3 | Risk notification only on manual recording | Medium (missed alerts) | Medium | FIXED + Grace Period Fix PLANNED |
| 4 | Inspection reminder late for late-month planned dates | Medium (confusing UX) | Medium | PLANNED |

### Implementation Order

1. ~~**Bug 1** (bell label)~~ — DONE
2. ~~**Bug 3 Phase 1** (risk notification on month boundary)~~ — DONE
3. ~~**Bug 2 Phase 1** (AI presenter consistency)~~ — DONE
4. ~~**Bug 2 Phase 2** (escalation toggle)~~ — DONE
5. **Bug 3 Phase 2** (risk notification grace period removal) — PLANNED
6. **Inspection Reminder Overhaul** (3 notification types) — PLANNED

---

## Escalation Toggle Plan (Bug 2 — Phase 2)

**Date:** August 28, 2026
**Status:** PLANNED — not yet implemented

### Rationale

The escalation feature (gap-based health badge bumped by open issues, aging issues, high risks, pending extensions) adds meaningful risk signals but introduces complexity:

1. **Inconsistency with notifications:** Risk notifications use `ProjectStatusService::getRiskLevel()` (gap-only). Escalation makes the dashboard show "At Risk" while the notification says "On Track." Users see contradictory signals for the same project.
2. **Escalation logic needs refinement:** The current thresholds (any open High/Critical issue, any issue > 30 days, any high-risk risk score >= 7, any pending extension) are blunt — a single low-severity open issue triggers escalation. This needs tuning before production use.
3. **Panel simplicity:** For the pre-oral presentation, a simpler gap-only system is easier to explain and defend. Escalation can be enabled later when the logic is more mature.
4. **Other system parts need attention:** Reducing complexity here frees effort for improving core features (regression accuracy, notification reliability, etc.).

### What Changes When Escalation Is OFF

| Component | Escalation ON (current) | Escalation OFF (toggle) |
|---|---|---|
| Dashboard health badge | Gap + escalation factors | Gap only |
| Project index health badge | Gap + escalation factors | Gap only |
| AI Prediction tab health | Gap + escalation factors | Gap only |
| Risk notifications | Gap only (always) | Gap only (unchanged) |
| Escalation info icon (⚠) | Visible when escalated | Hidden (never renders) |
| Escalation Factors section (AI tab) | Visible when escalated | Hidden (never renders) |

### Implementation (3 files)

1. **`config/ai.php`** — Add under a new `'health'` section:
   ```php
   'health' => [
       'escalation_enabled' => false,   // default OFF
   ],
   ```

2. **`app/Services/ProjectHealthService.php`** — Add config guard in `determine()`:
   ```php
   if (config('ai.health.escalation_enabled', false) && $level === 'on_track') {
       $reason = $this->escalationReason($context);
       // ...
   }
   ```

3. **`tests/Unit/Services/ProjectHealthServiceTest.php`** — Add `config(['ai.health.escalation_enabled' => true])` in escalation test setUp.

### To Re-Enable Escalation

Change `config/ai.php` line:
```php
'escalation_enabled' => true,
```

All surfaces automatically start showing escalation. No code changes needed.

---

## Risk Notification Grace Period Fix (Bug 3 — Phase 2)

**Date:** August 28, 2026
**Status:** PLANNED — not yet implemented

### Problem

The `sendRiskLevelChangeNotifications()` method uses a 7-day grace period (inherited from `sendOverdueInspectionReminders`). This delays the risk notification incorrectly at month boundaries.

**Example with baseline planned_date = Sep 14, simulated date = Sep 1:**
- `$now->subDays(7)` = Aug 25
- Query: `planned_date <= Aug 25`
- Sep 14 is NOT ≤ Aug 25 → **notification skipped**
- User expects the notification to fire when the baseline is due (Sep 14), not 7 days later

**Example with baseline planned_date = Aug 25, simulated date = Sep 1:**
- `$now->subDays(7)` = Aug 25
- Query: `planned_date <= Aug 25`
- Aug 25 IS ≤ Aug 25 → notification fires on Sep 1
- But the baseline was due Aug 25 — firing on Sep 1 is already late

### Root Cause

Risk notifications are mathematical (gap-based), not action-based. The grace period was designed for inspections (give staff time to inspect before alerting). Risk level changes should fire immediately when the baseline's `planned_date` arrives.

### Fix

Remove `$graceDays` from `sendRiskLevelChangeNotifications()`. Change the query from:
```php
->where('planned_date', '<=', $now->copy()->subDays($graceDays))
```
to:
```php
->where('planned_date', '<=', $now)
```

Remove the `$graceDays` parameter from the method signature.

### Expected Behavior After Fix

| Baseline planned_date | Simulated date | Fires? |
|---|---|---|
| Sep 14 | Sep 1 | No — Sep 14 not ≤ Sep 1 |
| Sep 14 | Sep 14 | **Yes** — fires on the due date |
| Aug 25 | Aug 25 | **Yes** — fires on the due date |

### Effort

Low — 1 file, ~5 lines changed.

---

## Inspection Reminder Overhaul

**Date:** August 28, 2026
**Status:** PLANNED — not yet implemented

### Problem

The current `OverdueInspectionReminder` has a 7-day grace period that causes issues for late-month planned dates:

**Example with planned_date = Aug 25, grace = 7 days:**
- Grace period: fires when `planned_date < now - 7`
- Aug 25: `Aug 25 < Aug 18`? No → waits
- Sep 1: `Aug 25 < Aug 25`? No → waits
- Sep 2: `Aug 25 < Aug 26`? **Yes** → fires on Sep 2

The reminder fires8 days into September for an inspection planned in August. This is confusing for users.

### Solution: Three Notification Types

Replace the single `OverdueInspectionReminder` with three complementary notification types:

| # | Notification | When it fires | Purpose |
|---|---|---|---|
| 1 | `PlannedInspectionReminder` | On the planned_date itself | "Today is your scheduled inspection" |
| 2 | `MonthEndInspectionReminder` | Last 7 days of month, no inspection done | "Month is almost over, inspect now" |
| 3 | `OverdueInspectionReminder` | After planned_date passes with no inspection | "You missed this inspection" |

### How They Interact

**Scenario 1: planned_date = Aug 15 (early/mid month)**

| Date | Notification | Why |
|---|---|---|
| Aug 15 | PlannedInspectionReminder | On the planned date |
| Aug 22 | OverdueInspectionReminder |7 days after planned date (no grace needed — fire on time) |
| Aug 25 | MonthEndInspectionReminder | Last7 days of month, no inspection |
| Sep 1 | OverdueInspectionReminder | Crossed month → fires immediately (dedupe handles if already sent) |

**Scenario 2: planned_date = Aug 25 (6 days before Sep)**

| Date | Notification | Why |
|---|---|---|
| Aug 25 | PlannedInspectionReminder | On the planned date |
| Aug 25 | MonthEndInspectionReminder | Last7 days of month (same day) |
| Aug 26-31 | *(nothing)* | Grace period for OverdueInspection |
| Sep 1 | OverdueInspectionReminder | Crossed month → fires immediately |

**Scenario 3: planned_date = Aug 28 (3 days before Sep)**

| Date | Notification | Why |
|---|---|---|
| Aug 28 | PlannedInspectionReminder | On the planned date |
| Aug 25 | MonthEndInspectionReminder | Fires first (last7 days of month) |
| Sep 1 | OverdueInspectionReminder | Crossed month → fires immediately |

### OverdueInspectionReminder Simplification

Remove the grace period entirely from `OverdueInspectionReminder`. The three types cover all phases:

```php
// Current (with grace):
return $baseline->planned_date->lt(Carbon::now()->subDays($graceDays));

// New (no grace):
return $baseline->planned_date->lt(Carbon::now());
```

### Files to Change

| File | Change |
|---|---|
| `app/Notifications/PlannedInspectionReminder.php` | **New** — fires on planned_date |
| `app/Notifications/MonthEndInspectionReminder.php` | **New** — fires in last7 days of month |
| `app/Console/Commands/SendNotifications.php` | Add 2 new methods, simplify `sendOverdueInspectionReminders` (remove grace), remove grace from `sendRiskLevelChangeNotifications` |
| `resources/views/notifications/partials/bell.blade.php` | Display logic for 2 new types |
| `app/Http/Controllers/NotificationController.php` | `humanType()` and `title()` for 2 new types |

### Deduplication

Each notification type uses a distinct dedupe key:
- `PlannedInspectionReminder`: `planned_inspection:{project_id}:{month_label}`
- `MonthEndInspectionReminder`: `month_end_inspection:{project_id}:{month_label}`
- `OverdueInspectionReminder`: `overdue_inspection:{project_id}:{month_label}` (existing)

No conflicts between types — a project can receive all three for the same month.

### Effort

Medium — 2 new files, 3 modified files, ~80-100 lines total.

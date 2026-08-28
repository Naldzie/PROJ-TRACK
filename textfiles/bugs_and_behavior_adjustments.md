# Bugs & Behavior Adjustments

**Date:** August 28, 2026
**Status:** DOCUMENTED — awaiting discussion before implementation

---

## Bug 1: Notification Bell Dropdown Shows Wrong Label for Risk Level Changes

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

| # | Bug | Severity | Fix Effort | Blocks Testing? |
|---|---|---|---|---|
| 1 | Bell dropdown wrong label | Low (cosmetic) | Low | No |
| 2 | AI tab vs dashboard health inconsistency | Medium (UX confusion) | Low-Medium | Needs discussion |
| 3 | Risk notification only on manual recording | Medium (missed alerts) | Medium | No |

### Implementation Order (suggested)

1. **Bug 1** (bell label) — quick fix, no design decisions needed
2. **Bug 3** (notification trigger) — clear fix, adds meaningful functionality
3. **Bug 2** (health inconsistency) — needs team decision on Option A/B/C/D before implementation

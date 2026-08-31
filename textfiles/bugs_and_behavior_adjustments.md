# Bugs & Behavior Adjustments

**Date:** August 28, 2026 (updated Aug 30)
**Status:** Bugs 1–10 + E1–E8 FIXED. E6–E8 implemented on `phase-ui-loading-and-staff-permissions`.

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

**Status: Escalation Toggle (Phase 2) — FIXED.** `config('ai.health.escalation_enabled')` added, default `false`. Guard in `ProjectHealthService::determine()`. Tests updated.

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

**Status: Grace Period Fix (Phase 2) — FIXED.** Removed `$graceDays` parameter. Query changed to fire immediately when `planned_date <= $now`. Risk notifications are mathematical (gap-based), not action-based — no grace needed.

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
| 2 | AI tab vs dashboard health inconsistency | Medium (UX confusion) | Low-Medium | FIXED |
| 3 | Risk notification only on manual recording | Medium (missed alerts) | Medium | FIXED |
| 4 | Inspection reminder late for late-month planned dates | Medium (confusing UX) | Medium | FIXED |
| 5 | Notification scan includes archived projects | Medium (phantom alerts) | Low | FIXED |
| 6 | Notification page shows "Issue reported" for new inspection types | Medium (wrong label) | Low | FIXED |
| 7 | Email notifications delayed 30-60 min (synchronous SMTP blocking) | High (missed alerts) | Medium | IN PROGRESS |

### Implementation Order

1. ~~**Bug 1** (bell label)~~ — DONE
2. ~~**Bug 3 Phase 1** (risk notification on month boundary)~~ — DONE
3. ~~**Bug 2 Phase 1** (AI presenter consistency)~~ — DONE
4. ~~**Bug 2 Phase 2** (escalation toggle)~~ — DONE
5. ~~**Bug 3 Phase 2** (risk notification grace period removal)~~ — DONE
6. ~~**Inspection Reminder Overhaul** (3 notification types)~~ — DONE
7. ~~**Bug 5** (archived project scan exclusion)~~ — DONE
8. ~~**Bug 6** (notification page label for new inspection types)~~ — DONE
9. **Bug 7** (email notification delay — queue notifications) — IN PROGRESS

---

## Escalation Toggle Plan (Bug 2 — Phase 2)

**Date:** August 28, 2026
**Status:** FIXED — `config('ai.health.escalation_enabled')` added, default false. Guard in `ProjectHealthService::determine()`. Tests updated.

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
**Status:** FIXED — Removed `$graceDays` parameter. Query now fires immediately when `planned_date <= $now`.

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
**Status:** FIXED — 3 notification types created. Grace period removed from overdue. Current-month exclusion prevents overlap. All tests passing (985 total).

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
| Aug 22 | OverdueInspectionReminder | 7 days after planned date (fires on time, no grace) |
| Aug 25–31 | MonthEndInspectionReminder | Last 7 days of month, no inspection |
| Sep 1 | OverdueInspectionReminder | Crossed month → fires immediately (dedupe handles if MonthEnd already sent) |

**Scenario 2: planned_date = Aug 25 (6 days before Sep)**

| Date | Notification | Why |
|---|---|---|
| Aug 25 | PlannedInspectionReminder | On the planned date |
| Aug 25 | MonthEndInspectionReminder | Last 7 days of month (same day) |
| Aug 26–31 | *(nothing)* | Current month excluded from overdue — MonthEnd handles it |
| Sep 1 | OverdueInspectionReminder | Crossed month → fires immediately |

**Scenario 3: planned_date = Aug 28 (3 days before Sep)**

| Date | Notification | Why |
|---|---|---|
| Aug 25 | MonthEndInspectionReminder | Fires first (last 7 days of month) |
| Aug 28 | PlannedInspectionReminder | On the planned date |
| Sep 1 | OverdueInspectionReminder | Crossed month → fires immediately |

### OverdueInspectionReminder Simplification

Two changes to `sendOverdueInspectionReminders`:

1. **Remove the grace period** — fires immediately when `planned_date < now`
2. **Exclude current month** — overdue only covers previous months. The current month is handled by PlannedInspectionReminder + MonthEndInspectionReminder, preventing duplicate notifications.

```php
// Current (with grace, all months):
return $baseline->planned_date->lt(Carbon::now()->subDays($graceDays));

// New (no grace, excludes current month):
$currentMonthLabel = Carbon::now()->format('Y-m');
// ... filter:
if ($baseline->month_label === $currentMonthLabel) {
    return false;
}
return $baseline->planned_date->lt(Carbon::now());
```

### diffInDays Fix in MonthEndInspectionReminder

`sendMonthEndInspectionReminders` needed `abs()` on the `diffInDays` call. Carbon returns a **negative** value when end-of-month is in the future:

```php
// Bug: returns -21 for Aug 10 → Aug 31 (negative!)
$daysUntilMonthEnd = (int) $now->copy()->endOfMonth()->diffInDays($now);

// Fix: wrap with abs()
$daysUntilMonthEnd = (int) abs($now->copy()->endOfMonth()->diffInDays($now));
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

---

## Bug 5: Notification Scan Includes Archived Projects

**Date:** August 28, 2026
**Status:** FIXED — Added `->where('is_archived', false)` to the scan query.

### Problem

After archiving projects and running the notification scan, archived projects still appeared in the scan output and generated overdue inspection notifications. The scan should only process active (non-archived) projects.

### Root Cause

The `SendNotifications` command filters projects by `status = 'Ongoing'` but does not check `is_archived`. When a project is archived via `ProjectController::archive()`, only `is_archived = true` and `archived_at = now()` are set — the `status` field stays `'Ongoing'`. So archived projects pass the `whereIn('status', ['Ongoing'])` filter.

Every other controller in the app (`DashboardController`, `ProjectController`, contractor `DashboardController`) already adds `->where('is_archived', false)`. The scan command simply missed this filter.

### Fix

**File:** `app/Console/Commands/SendNotifications.php` (line 58)

```php
// Before:
$projects = Project::whereIn('status', ['Ongoing'])
    ->whereNotNull('planned_end_date')
    ->with([...])
    ->get();

// After:
$projects = Project::whereIn('status', ['Ongoing'])
    ->where('is_archived', false)
    ->whereNotNull('planned_end_date')
    ->with([...])
    ->get();
```

### Effort

Low — 1 line added.

---

## Bug 6: Notification Page Shows "Issue Reported" for New Inspection Types

**Date:** August 28, 2026
**Status:** FIXED — Added `PlannedInspectionReminder` and `MonthEndInspectionReminder` branches to `_list.blade.php`.

### Problem

When `PlannedInspectionReminder` or `MonthEndInspectionReminder` notifications appear in the bell dropdown with correct labels, the full notification page (`_list.blade.php`) displays them as "Issue reported" with the default green icon.

### Root Cause

`_list.blade.php` was written before the new inspection notification types were added (Phase N5 / Inspection Reminder Overhaul). The bell widget (`bell.blade.php`) was updated to handle the new types, but the `_list.blade.php` page partial was not. The nested ternary logic for `$title`, `$cls`, `$icon`, and `$nAnchor` had no branches for `PlannedInspectionReminder` or `MonthEndInspectionReminder`, so they fell through to the default "Issue reported" case.

### Fix

**File:** `resources/views/notifications/_list.blade.php`

1. Replaced nested ternaries with `match(true)` expressions for `$cls`, `$icon`, `$title`.
2. Added branches for both new types:

| Type | `$cls` | `$icon` | `$title` |
|---|---|---|---|
| `PlannedInspectionReminder` | `blue` | `bi-calendar-check` | "Inspection scheduled today — {project} ({month})" |
| `MonthEndInspectionReminder` | `amber` | `bi-calendar-event` | "Month ending soon — inspect {project} ({month})" |

3. Updated `$nAnchor` — both new types link to `#baseline-schedule`.
4. Added meta lines — both show "Planned inspection: {date}" beneath the title (matching OverdueInspectionReminder's existing meta pattern).

### Effort

Low — 1 file, ~40 lines restructured.

---

## Bug 7: Email Notifications Delayed 30-60 Minutes (Synchronous SMTP Blocking)

**Date:** August 28, 2026
**Status: RESOLVED via Bug 8** — The delay was specific to RiskLevelChanged notifications (missing `Queueable` trait), not a general SMTP blocking issue. Bug 8 fix resolved it. The queued approach (`SendNotificationEmailJob`) was attempted and reverted — it was unnecessary. Other notification types (DeadlineReminder, IssueReported, etc.) were never delayed.

### Problem

Risk level change notifications had delayed or missing Gmail delivery, while all other notification types sent Gmail within seconds. This was initially misdiagnosed as a general SMTP bottleneck across all notification types.

### Root Cause

`RiskLevelChanged` was the **only** notification class missing the `use Queueable` trait. Without it, Laravel's mail channel pipeline handled the notification differently, causing SMTP delays or silent failures. All other 7 notification classes had `Queueable`.

### What Was Tried (Unnecessary)

| Attempt | Approach | Result |
|---|---|---|
| Phase 1 | `ShouldQueue` on all notification classes | FAILED — queued ALL channels including `database`, breaking in-app notifications |
| Phase 2 | Split channels: `via(['database'])` only + `SendNotificationEmailJob` | FAILED — `NotificationSender` not container-resolvable; required separate `queue:listen` process |

These attempts were unnecessary because the root cause was isolated to one notification class missing a trait.

### Actual Fix

**Bug 8:** Added `use Queueable;` trait to `RiskLevelChanged.php`. Gmail delivery for risk level notifications now works within seconds, matching all other notification types.

### Retained Improvements from Bug 7 Effort

The following controller try/catch guards were added and retained (they prevent notification failures from crashing HTTP requests regardless of email delivery method):

| Controller | Method | Notes |
|---|---|---|
| `RiskController.php` | `store()` | try/catch around high-risk notification |
| `RiskController.php` | `convertToIssue()` | try/catch around issue-reported notification |
| `IssueController.php` | `store()` | try/catch around issue-reported notification |
| `ProjectExtensionController.php` | `notifyExtension()` | try/catch around extension status notification |
| `SendNotifications.php` | `handle()` | per-method try/catch for scan resilience |

---

## Bug 8: RiskLevelChanged Missing `Queueable` Trait (Gmail Delay Root Cause)

**Date:** August 29, 2026
**Status: FIXED & TESTED** — Added `use Queueable;` trait and import. Confirmed working.

### Problem

Risk level change notifications (both event-triggered and scan-triggered) have delayed or missing Gmail delivery, while all other notification types send Gmail within seconds.

### Root Cause

`RiskLevelChanged` is the **only** notification class missing the `use Queueable` trait and its import. All other 7 notification classes have it.

| Notification Class | `use Queueable`? |
|---|---|
| `DeadlineReminder` | YES |
| `PlannedInspectionReminder` | YES |
| `MonthEndInspectionReminder` | YES |
| `OverdueInspectionReminder` | YES |
| `IssueReported` | YES |
| `RiskReported` | YES |
| `ExtensionStatusChanged` | YES |
| **`RiskLevelChanged`** | **NO** |

Without `Queueable`, the notification lacks queue metadata (`$queue`, `$connection`, `$chain`, etc.) that Laravel's mail channel pipeline expects. This causes the mail dispatch to be handled differently — resulting in SMTP delays or silent failures.

### Fix

**File:** `app/Notifications/RiskLevelChanged.php`

Add `use Illuminate\Bus\Queueable;` import and `use Queueable;` inside the class body (matching all other notification classes).

### Effort

Low — 2 lines added.

---

## Bug 9: Scan Risk Level Notification Shows Wrong Progress Data

**Date:** August 29, 2026
**Status: FIXED & TESTED** — Added `->reorder()` to clear relationship's base ordering. Confirmed working.

### Problem

The scan-triggered `RiskLevelChanged` notification shows incorrect progress values. Example: planned 40%, actual 2%, variance -38% — when the actual latest progress should be much higher (e.g., 20%). The notification is using the **first** progress record ever recorded instead of the **latest**.

### Root Cause

The `progressHistory()` relationship in `app/Models/Project.php` has a default `orderBy('inspection_date', 'asc')`:

```php
public function progressHistory()
{
    return $this->hasMany(ProjectProgressHistory::class)->orderBy('inspection_date', 'asc');
}
```

In `sendRiskLevelChangeNotifications()` (line 373), the query:

```php
$lastRecorded = $project->progressHistory()
    ->orderBy('month_label', 'desc')
    ->first();
```

Laravel **appends** `orderBy` calls — it does not replace the relationship's base ordering. The actual SQL becomes:

```sql
SELECT * FROM project_progress_history
WHERE project_id = ?
ORDER BY inspection_date ASC, month_label DESC
LIMIT 1
```

This returns the **earliest** record (lowest `inspection_date`), not the latest.

### Impact

The notification displays:
- **Actual progress**: The very first progress value (e.g., 2%) instead of latest (e.g., 20%)
- **Variance**: Wildly wrong (e.g., 2 - 40 = -38 instead of the real variance)
- This affects both the in-app notification and the Gmail notification

### Fix

**File:** `app/Console/Commands/SendNotifications.php`, line 373

Add `->reorder()` to clear the relationship's base ordering:

```php
// Before:
$lastRecorded = $project->progressHistory()
    ->orderBy('month_label', 'desc')
    ->first();

// After:
$lastRecorded = $project->progressHistory()
    ->reorder()
    ->orderBy('month_label', 'desc')
    ->first();
```

### Effort

Low — 1 line added.

---

## Bug 10: RiskLevelChanged Recipients Missing `->unique('id')`

**Date:** August 29, 2026
**Status: FIXED & TESTED** — Added `->unique('id')` to recipients. Confirmed working.

### Problem

In `ProjectProgressController.php`, the RiskLevelChanged notification recipients collection does not call `->unique('id')`. If the same user resolves as both admin and staff/contractor, they receive duplicate notifications.

### Comparison

| Dispatch Point | `->unique('id')`? |
|---|---|
| `RiskController.php` (store) | YES |
| `RiskController.php` (convertToIssue) | YES |
| `IssueController.php` (store) | YES |
| `ProjectExtensionController.php` (notifyExtension) | YES |
| **`ProjectProgressController.php` (RiskLevelChanged)** | **NO** |

### Fix

**File:** `app/Http/Controllers/ProjectProgressController.php`, line 509

Add `->unique('id')`:

```php
// Before:
$notifService->notifyMany($recipients, $notification);

// After:
$notifService->notifyMany($recipients->unique('id'), $notification);
```

### Effort

Low — 1 line changed.

---

## Enhancement 1: Issue Notification Should Include Assigned Staff

**Date:** August 29, 2026
**Status: FIXED & TESTED** — Added `$service->staffForProject($project)` to IssueReported recipients. Confirmed working.

### Problem

`IssueReported` notifications are sent to admins + contractor only. The assigned staff (project manager) should also be notified since they manage the project.

### Current Recipients

| Notification Type | Admins | Staff | Contractor |
|---|---|---|---|
| `IssueReported` | YES | **NO** | YES |
| `RiskReported` | YES | NO | YES |
| `RiskLevelChanged` | YES | YES | YES |
| `ExtensionStatusChanged` | YES | YES | YES |

### Fix

**File:** `app/Http/Controllers/IssueController.php`, around line 143

Add `$service->staffForProject($project)` to the recipients collection (same pattern as RiskLevelChanged dispatch in ProjectProgressController).

### Effort

Low — ~3 lines added.

---

## Enhancement 2: Remove Contractor from Inspection Notifications

**Date:** August 29, 2026
**Status: FIXED & TESTED** — Filtered contractor from inspection method recipients via `$internalRecipients`. Tests updated. Confirmed working.

### Problem

Contractors are external users. They don't need to know about missing inspections — that's an internal PMU concern. The client confirmed contractors should only receive notifications relevant to their work (deadlines, issues, extensions).

### Affected Notification Types

| Type | Contractor Should Receive? |
|---|---|
| `DeadlineReminder` | YES — they need to know about deadlines |
| `PlannedInspectionReminder` | **NO** — internal only |
| `MonthEndInspectionReminder` | **NO** — internal only |
| `OverdueInspectionReminder` | **NO** — internal only |
| `RiskLevelChanged` | YES — they need to know about risk |
| `IssueReported` | YES — they need to know about issues |
| `ExtensionStatusChanged` | YES — they need to know about extensions |

### Fix

**File:** `app/Console/Commands/SendNotifications.php`

The scan's `handle()` loop builds a shared `$recipients` collection that includes the contractor. The inspection methods (`sendPlannedInspectionReminders`, `sendMonthEndInspectionReminders`, `sendOverdueInspectionReminders`) should filter out the contractor before calling `notifyMany()`.

Option A: Pass separate recipient sets per notification type.
Option B: Filter contractor inside each inspection method.
Option C: Build inspection-specific recipients inside each method (like RiskLevelChanged already does).

Recommended: Option B — filter out contractor in each inspection method before calling `$service->notifyMany()`.

### Effort

Low — ~6 lines added across 3 methods.

---

## Enhancement 3: Overdue Deadline In-App Notification Enhancement

**Date:** August 29, 2026
**Status: OPEN** — Needs implementation. Data is already in `toDatabase()` payload but Blade template doesn't display it.

### Problem

The overdue deadline notification's in-app display doesn't show the due date or how many days overdue — but the Gmail version does. Users want the same detail in the bell/notification inbox.

### Current In-App Display

```
Project overdue — {project_title}
```

### Desired In-App Display

```
Project overdue — {project_title}
Due: {planned_end_date} | Overdue by {days} day(s)
```

### Fix

Update the notification list/bell template to show due date and overdue days from the notification data (`planned_end_date` and `days_remaining` are already in `toDatabase()` payload).

### Effort

Low — 1–2 lines in Blade template.

---

## Enhancement 4: Risk Level Change Notification on Edit/Delete Progress

**Date:** August 29, 2026
**Status: OPEN** — Needs implementation.

### Problem

`RiskLevelChanged` notification only fires when progress is **recorded** via `recordProgress()` (`ProjectProgressController.php:465-516`). It does **not** fire when:

- A user **edits** the latest progress record (`editProgressRecord()` — line 75-243)
- An admin **deletes** the latest progress record (`destroyProgress()` — line 245-356)

Both edit and delete can change the project's risk level, but no notification is sent.

### Current Behavior

| Action | Recalculates Variance? | Fires RiskLevelChanged? | Updates previous_risk_level? |
|---|---|---|---|
| `recordProgress()` | YES | YES | YES |
| `editProgressRecord()` | YES (line 172) | **NO** | **NO** |
| `destroyProgress()` | **NO** | **NO** | **NO** |

### Fix

#### `editProgressRecord()` — after sync block (~line 222)

Add risk level change detection (same pattern as `recordProgress()` lines 465-516):
1. Compute new risk level from updated variance
2. Compare with `$project->previous_risk_level`
3. Fire `RiskLevelChanged` if changed (exclude `auth()->id()` from recipients)
4. Update `$project->previous_risk_level`

#### `destroyProgress()` — after sync/prune block (~line 341)

After deleting the latest record, recalculate risk level from the new latest record:
1. Get the new latest progress record (after deletion)
2. Derive risk level from its variance
3. Compare with `$project->previous_risk_level`
4. Fire `RiskLevelChanged` if changed (exclude `auth()->id()` from recipients)
5. Update `$project->previous_risk_level`

### Effort

Medium — ~30-40 lines added across 2 methods.

---

## Enhancement 5: Trigger User Exclusion for Gmail (All Event-Triggered Notifications)

**Date:** August 29, 2026
**Status: OPEN** — Needs implementation.

### Problem

Most event-triggered notifications do **not** exclude the user who triggered the event. The user receives their own notification via both in-app and Gmail.

`ProjectExtensionController::notifyExtension()` already excludes the trigger user (lines 1476, 1495, 1500). All other event-triggered notifications should follow the same pattern.

### Current State

| Controller | Method | Excludes trigger user? |
|---|---|---|
| `ProjectExtensionController` | `notifyExtension()` | **YES** (all channels) |
| `RiskController` | `store()` | NO |
| `RiskController` | `convertToIssue()` | NO |
| `IssueController` | `store()` | NO |
| `ProjectProgressController` | `recordProgress()` (RiskLevelChanged) | NO |
| `ProjectProgressController` | `editProgressRecord()` (RiskLevelChanged) | N/A (not yet implemented — see E4) |
| `ProjectProgressController` | `destroyProgress()` (RiskLevelChanged) | N/A (not yet implemented — see E4) |

### Fix

For each event-triggered notification, add `->filter(fn ($u) => $u->id !== auth()->id())` to the recipients collection before calling `notifyMany()`. This excludes the triggering user from **both** database and Gmail notifications (full exclusion, matching `ProjectExtensionController` pattern).

| File | Line(s) | Change |
|---|---|---|
| `RiskController.php` `store()` | ~120-125 | Add `->filter(fn ($u) => $u->id !== $user->id)` |
| `RiskController.php` `convertToIssue()` | ~117-130 | Add `->filter(fn ($u) => $u->id !== $user->id)` |
| `IssueController.php` `store()` | ~143-153 | Add `->filter(fn ($u) => $u->id !== auth()->id())` |
| `ProjectProgressController.php` `recordProgress()` | ~486-509 | Add `->filter(fn ($u) => $u->id !== auth()->id())` |
| `ProjectProgressController.php` `editProgressRecord()` | new block (E4) | Include exclusion in new code |
| `ProjectProgressController.php` `destroyProgress()` | new block (E4) | Include exclusion in new code |

### Effort

Low-Medium — ~12-15 lines added across 4 existing controllers (6 dispatch points).

---

## Summary

| # | Bug/Enhancement | Severity | Effort | Status |
|---|---|---|---|---|
| 1 | Bell dropdown wrong label | Low (cosmetic) | Low | FIXED |
| 2 | AI tab vs dashboard health inconsistency | Medium (UX) | Low-Medium | FIXED |
| 3 | Risk notification only on manual recording | Medium (missed alerts) | Medium | FIXED |
| 4 | Inspection reminder late for late-month dates | Medium (UX) | Medium | FIXED |
| 5 | Notification scan includes archived projects | Medium (phantom alerts) | Low | FIXED |
| 6 | Notification page wrong label for new types | Medium (wrong label) | Low | FIXED |
| 7 | Email notifications delayed (SMTP blocking) | High (missed alerts) | Medium | RESOLVED via Bug 8 |
| 8 | RiskLevelChanged missing Queueable trait | High (Gmail delay) | Low | FIXED & TESTED |
| 9 | Scan risk level shows wrong progress data | High (wrong info) | Low | FIXED & TESTED |
| 10 | RiskLevelChanged recipients missing unique | Low (duplicates) | Low | FIXED & TESTED |
| E1 | Issue notification missing assigned staff | Medium (missed alerts) | Low | FIXED & TESTED |
| E2 | Remove contractor from inspection notifications | Low (relevance) | Low | FIXED & TESTED |
| E3 | Overdue deadline in-app notification enhancement | Low (UX) | Low | IMPLEMENTED & TESTED |
| E4 | Risk level change on edit/delete progress | Medium (missed alerts) | Medium | FIXED & TESTED (live-gap approach) |
| E5 | Trigger user exclusion for Gmail (event notifications) | Medium (UX) | Low-Medium | IMPLEMENTED & TESTED (risk notifications deferred) |
| E6 | Loading state for progress save + event-triggered modals | Medium (UX / double-save) | Medium | IMPLEMENTED |
| E7 | Timeline dots show missing progress for past months | Medium (UX gap) | Low | IMPLEMENTED |
| E8 | Assigned staff delete progress + Recorded By visibility | Medium (permission gap) | Low | IMPLEMENTED |

### Implementation Order

1. ~~**Bug 1** (bell label)~~ — DONE
2. ~~**Bug 3 Phase 1** (risk notification on month boundary)~~ — DONE
3. ~~**Bug 2 Phase 1** (AI presenter consistency)~~ — DONE
4. ~~**Bug 2 Phase 2** (escalation toggle)~~ — DONE
5. ~~**Bug 3 Phase 2** (risk notification grace period removal)~~ — DONE
6. ~~**Inspection Reminder Overhaul** (3 notification types)~~ — DONE
7. ~~**Bug 5** (archived project scan exclusion)~~ — DONE
8. ~~**Bug 6** (notification page label for new inspection types)~~ — DONE
9. ~~**Bug 7** (email notification delay — resolved via Bug 8)~~ — RESOLVED
10. ~~**Bug 8** (RiskLevelChanged Queueable trait)~~ — DONE & TESTED
11. ~~**Bug 9** (scan risk level wrong data)~~ — DONE & TESTED
12. ~~**Bug 10** (recipients missing unique)~~ — DONE & TESTED
13. ~~**E1** (issue notification staff recipients)~~ — DONE & TESTED
14. ~~**E2** (remove contractor from inspection notifications)~~ — DONE & TESTED
15. ~~**E3** (overdue deadline in-app detail)~~ — DONE & TESTED
16. ~~**E4** (risk level change on edit/delete — needs fix)~~ — DONE & TESTED (live-gap approach)
17. ~~**E5** (trigger user exclusion for Gmail)~~ — DONE & TESTED (risk notifications deferred)
18. ~~**E6** (loading state for progress save + event-triggered modals)~~ — IMPLEMENTED
19. ~~**E7** (timeline dots missing progress indicator)~~ — IMPLEMENTED
20. ~~**E8** (assigned staff delete progress + Recorded By visibility)~~ — IMPLEMENTED

---

## Session Log: August 29, 2026 (Afternoon)

### Changes Implemented This Session

| Item | Change | Files | Status |
|---|---|---|---|
| Bug 8 | Added `use Queueable;` to `RiskLevelChanged` | `app/Notifications/RiskLevelChanged.php` | FIXED & TESTED |
| Bug 9 | Added `->reorder()` to scan risk level query | `app/Console/Commands/SendNotifications.php:373` | FIXED & TESTED |
| Bug 10 | Added `->unique('id')` to RiskLevelChanged recipients | `app/Http/Controllers/ProjectProgressController.php:509` | FIXED & TESTED |
| E1 | Added assigned staff to IssueReported recipients | `app/Http/Controllers/IssueController.php:143` | FIXED & TESTED |
| E2 | Excluded contractor from inspection notifications | `app/Console/Commands/SendNotifications.php:90` ($internalRecipients) | FIXED & TESTED |
| E3 | Added overdue deadline detail to notification list | `resources/views/notifications/_list.blade.php:141` | IMPLEMENTED & TESTED |
| E4 | Added risk level change detection to edit/delete | `app/Http/Controllers/ProjectProgressController.php` | FIXED & TESTED |
| E5 | Added trigger user exclusion to event notifications | `RiskController.php`, `IssueController.php`, `ProjectProgressController.php` | IMPLEMENTED & TESTED |

### User Testing Results (August 29, 2026)

| Item | Result | Notes |
|---|---|---|
| Bug 8 (Queueable) | WORKING | Gmail sends within seconds |
| Bug 9 (reorder) | WORKING | Correct progress data shown |
| Bug 10 (unique) | WORKING | No duplicate notifications |
| E1 (staff on issue) | WORKING | Assigned staff receives IssueReported |
| E2 (contractor excluded) | WORKING | Contractors don't get inspection notifications |
| E3 (overdue detail) | WORKING | Shows planned_end_date + overdue days |
| E4 (edit/delete risk) | WORKING | Live-gap approach: latest actual vs current month's plan |
| E5 (trigger exclusion — issue/risk) | WORKING | Users don't get their own IssueReported/RiskReported |
| E5 (trigger exclusion — risk level) | DEFERRED | Low priority, not tested yet |
| 5a/5b (inspection reminders) | WORKING | Tested in prior session, not touched by this session |
| 6 (notification page labels) | WORKING | Tested in prior session |

### Test Results

- **985 tests pass** (2 pre-existing `EmailDraftTest` failures unchanged)
- Tests updated: `RiskRegisterTest` (3 tests) and `IssueLifecycleTest` (1 test) updated for trigger user exclusion (create second admin as recipient)

---

## E4 Fix Plan: Live-Gap Calculation for Edit/Delete Risk Level

**Date:** August 29, 2026
**Status: FIXED** — Live-gap approach implemented. Uses latest actual vs current month's plan. Matches scan logic. Ready for user testing.

### Problem

The current E4 implementation uses the **stored variance** of the edited/deleted record to determine risk level. This is wrong because the stored variance compares actual vs planned **for that specific month**, not against the **current month's plan**.

### Example

| Month | Plan | Actual | Stored Variance |
|---|---|---|---|
| July | 18% | 15% | -3% |
| August | 28% | 20% | -8% |

Current simulated date: **August**

**Delete August's progress record:**
- Current code: reverts to July's stored variance (-3%) → **Low Risk** (WRONG)
- Correct: July's actual (15%) vs August's plan (28%) = -13% → **High Risk** (CORRECT)

The project is MORE behind (only 15% done, should be at 28%), but the system says LESS risk.

### Root Cause

Both `destroyProgress()` and `editProgressRecord()` use the **stored variance** from the progress record. The stored variance was computed at record creation time against **that month's plan**. After deletion/edit, the stored variance does not reflect the current project state relative to the current date.

### Correct Approach

Match the scan's logic (`sendRiskLevelChangeNotifications()` at `SendNotifications.php:367-426`):

1. Get the **latest remaining record** (actual progress)
2. Find the **current month's baseline** (`planned_date <= Carbon::now()`)
3. Compute **live variance** = latest actual - current month's plan
4. Use live variance to determine risk level

This is date-aware (respects `Carbon::now()` / simulation) and consistent with the scan.

### Implementation

#### `destroyProgress()` — replace current E4 block

After deleting the record and syncing prediction history:

```php
// Risk-level change detection after delete (live-gap approach)
$newLatest = $project->progressHistory()
    ->reorder()
    ->orderBy('month_label', 'desc')
    ->first();

$previousRiskLevel = $project->previous_risk_level;

if ($newLatest) {
    // Find current month's baseline plan (same as scan logic)
    $currentMonthBaseline = ProjectBaselineSchedule::where('project_id', $project->id)
        ->where('planned_date', '<=', Carbon::now())
        ->orderBy('planned_date', 'desc')
        ->first();

    if ($currentMonthBaseline) {
        $plannedProgress = (float) $currentMonthBaseline->planned_progress_percent;
        $actualProgress  = (float) $newLatest->actual_progress_percent;
        $liveVariance    = $actualProgress - $plannedProgress;

        $statusService = app(ProjectStatusService::class);
        $currentRiskLevel = $statusService->getRiskLevel(-$liveVariance)['label'];

        if ($previousRiskLevel !== null && $currentRiskLevel !== $previousRiskLevel) {
            // Fire RiskLevelChanged notification (exclude trigger user)
            try {
                $notifService = app(NotificationService::class);
                $recipients = collect();
                $recipients = $recipients->merge($notifService->admins());
                $staff = $notifService->staffForProject($project);
                if ($staff) { $recipients->push($staff); }
                $contractor = $notifService->contractorUserFor($project);
                if ($contractor) { $recipients->push($contractor); }

                $notification = new \App\Notifications\RiskLevelChanged(
                    $project->id,
                    $project->title,
                    $previousRiskLevel,
                    $currentRiskLevel,
                    $newLatest->month_label,
                    $liveVariance,
                    $actualProgress,
                    $plannedProgress,
                );
                $notifService->notifyMany($recipients->unique('id')->filter(fn ($u) => $u->id !== auth()->id()), $notification);
            } catch (\Throwable $e) {
                \Log::warning('Risk level notification failed (delete progress)', [
                    'project_id' => $project->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $project->update(['previous_risk_level' => $currentRiskLevel]);
    }
} elseif (! $newLatest) {
    // No progress records remain — clear stored risk level
    $project->update(['previous_risk_level' => null]);
}
```

#### `editProgressRecord()` — replace current E4 block

Same pattern after the edit is saved and synced:

```php
// Risk-level change detection after edit (live-gap approach)
if ($isMonthlyRecord && ! empty($record->month_label)) {
    $latestRecord = $project->progressHistory()
        ->reorder()
        ->orderBy('month_label', 'desc')
        ->first();

    $previousRiskLevel = $project->previous_risk_level;

    if ($latestRecord) {
        $currentMonthBaseline = ProjectBaselineSchedule::where('project_id', $project->id)
            ->where('planned_date', '<=', Carbon::now())
            ->orderBy('planned_date', 'desc')
            ->first();

        if ($currentMonthBaseline) {
            $plannedProgress = (float) $currentMonthBaseline->planned_progress_percent;
            $actualProgress  = (float) $latestRecord->actual_progress_percent;
            $liveVariance    = $actualProgress - $plannedProgress;

            $statusService = app(ProjectStatusService::class);
            $currentRiskLevel = $statusService->getRiskLevel(-$liveVariance)['label'];

            if ($previousRiskLevel !== null && $currentRiskLevel !== $previousRiskLevel) {
                // Fire RiskLevelChanged notification (exclude trigger user)
                // ... same pattern as destroyProgress block above ...
            }

            $project->update(['previous_risk_level' => $currentRiskLevel]);
        }
    }
}
```

### Key Differences from Current (Wrong) Code

| Aspect | Current (wrong) | Proposed (correct) |
|---|---|---|
| Variance source | Stored `$variancePercent` from the edited/deleted record | Live calculation: latest actual vs current month's plan |
| Month used for plan | The record's own month | Current month (same as scan) |
| Consistent with scan? | No | Yes |
| Handles date simulation? | No (uses stored value) | Yes (`Carbon::now()` respects simulation) |

### What Stays the Same

- `recordProgress()` — keeps using month-specific variance (user confirmed)
- E3, E5 — confirmed working, no changes
- All notification recipients, trigger user exclusion — no changes

### Effort

Low — replacing ~40 lines of wrong logic with ~50 lines of correct logic in 2 methods. Same file: `app/Http/Controllers/ProjectProgressController.php`.

---

## E6: Loading State for Progress Save + Event-Triggered Modals

**Date:** August 30, 2026
**Branch:** `phase-ui-loading-and-staff-permissions`
**Status: IMPLEMENTED**

### Problem

When the user clicks Save on the progress record modal (and other modals that dispatch event-triggered notifications), there is no visual feedback. The modal stays still, making it look like the save button was not clicked. This causes users to click again, resulting in duplicate entries. The delay is caused by synchronous email notification dispatch — especially `RiskLevelChanged` which sends emails to multiple recipients (admins + staff + contractor) via SMTP.

### Affected Modals (No Loading State)

| Modal | JS File | Lines |
|---|---|---|
| Record Progress | `baseline-schedule-record-progress-modal.js` | 138-309 (`saveMonthlyProgress()`) |
| Edit Progress | `baseline-schedule-edit-progress-modal.js` | 263-416 (`saveProgressEdit()`) |
| Delete Progress | `baseline-schedule-edit-progress-modal.js` | 421-464 (`deleteProgressRecord()`) |
| Add/Edit Issue | `issues-modal.js` | 481-614 |
| Add/Edit Risk | `risk-register.js` | 684-862 |
| Convert Risk to Issue | `risk-register.js` | 533-596 |

### Already Have Loading State (No Change Needed)

| Modal | JS File |
|---|---|
| Request Extension | `request_extension_modal.blade.php` (lines 470-557) |
| Review Extension | `review_extension_modal.blade.php` (lines 342-387) |

### Root Cause

None of the affected JS handlers disable the submit button, show a spinner, or change button text before sending the `fetch()` request. The only feedback comes after the response arrives (modal closes + page reloads 600ms later). During the SMTP delay window (1-10+ seconds per recipient), the UI appears frozen.

### Approach: Full Preloader Overlay

Per user preference: close the modal immediately on click, then show the full preloader overlay with a contextual message (e.g. "Saving your progress..."). Hide the preloader when the request completes.

### Implementation (6 phases)

#### Phase 1: Extend `preloader.js` to Support Re-Showing

The current `preloader.js` unmounts the `#pt-preloader` element after page load, making it unavailable for reuse. Add a `show(label)` method that re-injects the preloader HTML if it was unmounted.

**File:** `public/js/components/preloader.js`

Add `show(label)` method to `window.ProjTrackPreloader`:
1. If `#pt-preloader` was unmounted, re-inject the HTML from a stored template string
2. Update the label text (e.g. "Saving your progress...")
3. Set `opacity: 1`, `visibility: visible`, remove `pt-preloader--hidden` class
4. Reset `hideFired = false` so `.hide()` can work again

#### Phase 2: Progress Save/Edit/Delete Loading States

**File:** `public/js/admin/projects/baseline-schedule-record-progress-modal.js` — `saveMonthlyProgress()` (line 138)

After validation passes, before `fetch()`:
1. Close the record progress modal (`bootstrap.Modal.getInstance(...).hide()`)
2. Call `ProjTrackPreloader.show('Saving your progress...')`

In `.then()` (success) and `.catch()` (error):
1. Call `ProjTrackPreloader.hide()` before proceeding with existing success/error logic

---

**File:** `public/js/admin/projects/baseline-schedule-edit-progress-modal.js` — `saveProgressEdit()` (line 263)

Same pattern: close modal → show preloader → hide on completion.

---

**File:** `public/js/admin/projects/baseline-schedule-edit-progress-modal.js` — `deleteProgressRecord()` (line 421)

Inside the `showDeleteConfirmation` callback, before `fetch()`:
1. Close the delete confirmation modal
2. Call `ProjTrackPreloader.show('Deleting progress record...')`

In `.then()` and `.catch()`: hide preloader.

#### Phase 3: Shared Delete Confirmation Async Support

**File:** `resources/views/components/delete-confirmation-modal.blade.php`

Modify `confirmDeleteAction()` to:
1. Disable the `#deleteConfirmBtn` and show a spinner
2. If the callback returns a Promise, wait for it before closing the modal
3. If the callback is synchronous (backward-compatible), close immediately

This benefits all delete confirmations (issue attachments, risk attachments, risk delete, extension archive, progress delete).

#### Phase 4: Issue Modal Loading States

**File:** `public/js/admin/projects/issues-modal.js`

- Add Issue form submit (line 481): disable button + show preloader
- Edit Issue form submit (line 545): disable button + show preloader
- Close modal → preloader → hide on completion

#### Phase 5: Risk Modal Loading States

**File:** `public/js/admin/projects/risk-register.js`

- Add Risk (line 684): close modal → preloader → hide
- Edit Risk (line 804): close modal → preloader → hide
- Convert Risk to Issue (line 533): close modal → preloader → hide

#### Phase 6: Verify Extension Modals (No Changes Expected)

Request Extension and Review Extension already have loading states. Verify they work correctly after `preloader.js` changes. No code changes expected.

### Files Touched

| File | Change |
|---|---|
| `public/js/components/preloader.js` | Add `show(label)` method for re-injection |
| `public/js/admin/projects/baseline-schedule-record-progress-modal.js` | Add preloader around `saveMonthlyProgress()` |
| `public/js/admin/projects/baseline-schedule-edit-progress-modal.js` | Add preloader around `saveProgressEdit()` + `deleteProgressRecord()` |
| `resources/views/components/delete-confirmation-modal.blade.php` | Make `confirmDeleteAction()` handle async callbacks |
| `public/js/admin/projects/issues-modal.js` | Add preloader around add/edit issue |
| `public/js/admin/projects/risk-register.js` | Add preloader around add/edit risk, convert to issue |

### Effort

Medium — 6 JS files + 1 Blade file, ~80-100 lines total. Pattern is repetitive (close modal → show preloader → hide on completion).

---

## E7: Timeline Dots Should Reflect Missing Progress for Past Months

**Date:** August 30, 2026
**Branch:** `phase-ui-loading-and-staff-permissions`
**Status: IMPLEMENTED**

### Problem

The timeline dot state logic at `baseline-schedule.blade.php:193` treats ALL past months as `completed` regardless of whether a progress record exists:

```php
$dotState = ($hasActual || $isPastMonth) ? 'completed' : ($isCurrentMonth ? 'current' : 'future');
```

A past month with no progress record shows the same green checkmark as a properly recorded month. This is misleading — users cannot distinguish between months that were inspected and months that were skipped.

### Current Dot States

| State | Condition | Visual |
|---|---|---|
| `completed` | Has actual progress OR is past month | Green filled circle + checkmark |
| `current` | Matches current month, no progress yet | Purple filled circle + pulse animation |
| `future` | After current month | Gray bordered empty circle |

### Desired Dot States

| State | Condition | Visual |
|---|---|---|
| `completed` | Has actual progress record | Green filled circle + checkmark |
| `missing` | Past month, NO progress record | Orange filled circle + exclamation icon |
| `current` | Matches current month, no progress yet | Purple filled circle + pulse animation |
| `future` | After current month | Gray bordered empty circle |

### Implementation

#### Step 1: Update Dot State Logic

**File:** `resources/views/projects/project_detail/components/tabs/baseline-schedule.blade.php` (lines 190-193)

Change from:
```php
$hasActual = $latestProgress && $latestProgress->actual_progress_percent !== null;
$isCurrentMonth = $plan->month_label === $currentMonthLabel;
$isPastMonth = $plan->month_label < $currentMonthLabel;
$dotState = ($hasActual || $isPastMonth) ? 'completed' : ($isCurrentMonth ? 'current' : 'future');
```

To:
```php
$hasActual = $latestProgress && $latestProgress->actual_progress_percent !== null;
$isCurrentMonth = $plan->month_label === $currentMonthLabel;
$isPastMonth = $plan->month_label < $currentMonthLabel;
if ($hasActual) {
    $dotState = 'completed';
} elseif ($isPastMonth) {
    $dotState = 'missing';   // past month, no progress recorded
} elseif ($isCurrentMonth) {
    $dotState = 'current';
} else {
    $dotState = 'future';
}
```

#### Step 2: Add Missing Dot Icon

**File:** `baseline-schedule.blade.php` (lines 212-214)

Change from:
```blade
@if($dotState === 'completed')
    <i class="bi bi-check-lg"></i>
@endif
```

To:
```blade
@if($dotState === 'completed')
    <i class="bi bi-check-lg"></i>
@elseif($dotState === 'missing')
    <i class="bi bi-exclamation-lg"></i>
@endif
```

#### Step 3: Add CSS for `.missing` State

**File:** `public/css/admin/projects/project-detail.css`

Add after the `.timeline-dot.completed .timeline-dot-circle` rule (line ~1760):

```css
/* Past month with no progress record */
.timeline-dot.missing .timeline-dot-circle {
    background: #f97316;
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.2);
}
.timeline-dot.missing .bi-exclamation-lg {
    color: white;
    font-size: 10px;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}
```

#### Step 4: Update Tooltip for Missing Dots (Optional Enhancement)

**File:** `baseline-schedule.blade.php` (lines 226-230)

When `$latestProgress` is null for a past month, the tooltip already shows `—` for Actual. Add a note:

```blade
@if($dotState === 'missing')
    <div style="color: #f97316; font-size: 10px; margin-top: 4px;">No progress recorded</div>
@endif
```

### Files Touched

| File | Change |
|---|---|
| `resources/views/.../tabs/baseline-schedule.blade.php` | Add `missing` dot state + icon + tooltip note |
| `public/css/admin/projects/project-detail.css` | Add `.timeline-dot.missing` styles |

### Effort

Low — 1 Blade file (~10 lines changed) + 1 CSS file (~12 lines added).

---

## E8: Assigned Staff Delete Progress + Recorded By Visibility

**Date:** August 30, 2026
**Branch:** `phase-ui-loading-and-staff-permissions`
**Status: IMPLEMENTED**

### Problem

Assigned staff currently cannot delete progress records (admin-only check in controller) and cannot see the "Recorded By" column in the progress history table. Both capabilities should be available to assigned staff for project management.

### Current State

| Capability | Staff (assigned) | Admin |
|---|---|---|
| Add progress | YES (routes + UI exist) | YES |
| Edit latest record | YES (controller allows) | YES |
| Edit older records | NO (controller blocks) | YES |
| **Delete latest record** | **NO** (admin-only check) | YES |
| **See "Recorded By" column** | **NO** (admin-only check) | YES |

### Desired State (per user confirmation)

| Capability | Staff (assigned) | Admin |
|---|---|---|
| Add progress | YES | YES |
| Edit latest record | YES | YES |
| Edit older records | NO (unchanged) | YES |
| **Delete latest record** | **YES** (limited to latest, same as edit) | YES |
| **See "Recorded By" column** | **YES** | YES |

### Implementation

#### Step 1: Remove Admin-Only Check from `destroyProgress()`

**File:** `app/Http/Controllers/ProjectProgressController.php` (lines 311-317)

Remove or change the hard admin-only block:

```php
// BEFORE (lines 311-317):
if (! auth()->user() || auth()->user()->role !== 'admin') {
    return response()->json([
        'success' => false,
        'message' => 'Only admin users can delete progress records.',
    ], 403);
}
```

**Action:** Remove these lines entirely. The `authorizeProjectAccess()` at line 309 already ensures only admins or assigned staff can access. The existing "latest record only" guard at lines 326-337 already prevents deleting non-latest records.

#### Step 2: Show Delete Button for Assigned Staff in Progress Tab

**File:** `resources/views/projects/project_detail/components/tabs/progress.blade.php` (line 209)

Change from:
```blade
@if(auth()->user()->role === 'admin' && $loop->first)
```

To:
```blade
@if((auth()->user()->role === 'admin' || $isAssignedStaff) && $loop->first)
```

#### Step 3: Show "Recorded By" Column Header to Staff

**File:** `progress.blade.php` (line 115)

Change from:
```blade
@if(auth()->user()->role === 'admin')
```

To:
```blade
@if(in_array(auth()->user()->role, ['admin', 'staff']))
```

#### Step 4: Show "Recorded By" Column Data to Staff

**File:** `progress.blade.php` (line 173)

Change from:
```blade
@if(auth()->user()->role === 'admin')
```

To:
```blade
@if(in_array(auth()->user()->role, ['admin', 'staff']))
```

### Files Touched

| File | Change |
|---|---|
| `app/Http/Controllers/ProjectProgressController.php` | Remove admin-only check from `destroyProgress()` |
| `resources/views/.../tabs/progress.blade.php` | Show delete button + Recorded By for staff |

### Effort

Low — 1 controller change (delete 7 lines) + 1 Blade file (3 condition changes).

---

## Session Log: August 30, 2026

### Changes Implemented This Session

| Item | Change | Files | Status |
|---|---|---|---|
| Branch | Created `phase-ui-loading-and-staff-permissions` from `develop` | — | DONE |
| Branch | Rebased `phase-ui-loading-and-staff-permissions` onto `develop` (fast-forward teammate merge) | — | DONE |
| E6 | Implemented loading states for all modals | preloader.js, 5 JS files, 1 blade | IMPLEMENTED |
| E6 fix | Fixed preloader not closing — removed inline style overrides in `show()` + added `hide()` to all 7 success paths | preloader.js, 5 JS files | FIXED |
| E7 | Added missing dot state for past months with no progress | baseline-schedule.blade.php, project-detail.css | IMPLEMENTED |
| E8 | Staff can delete latest progress + see Recorded By | ProjectProgressController.php, progress.blade.php | IMPLEMENTED |

### E6 Preloader Fix Details

**Problem:** Preloader overlay showed on modal submit but never closed after process completed.

**Root cause 1:** `show()` set inline `style.opacity = '1'` and `style.visibility = 'visible'` which have higher CSS specificity than the class-based `.pt-preloader--hidden { opacity: 0 }` rule — the CSS fade-out transition was overridden.

**Fix 1:** Removed the two inline style lines from `show()`. The base `.pt-preloader` CSS already defines `opacity: 1; visibility: visible;`, so removing the `--hidden` class is enough to show it, and adding it back properly triggers the CSS transition.

**Root cause 2:** On success paths, `ProjTrackPreloader.hide()` was never called — only error handlers called it. The preloader stayed visible until page reload (or forever if carry-over modal opened).

**Fix 2:** Added `hide()` calls to all 7 success paths:
- `baseline-schedule-record-progress-modal.js` — success path before carry-over/reload branching
- `baseline-schedule-edit-progress-modal.js` — `finalizeReload()` before setTimeout
- `baseline-schedule-edit-progress-modal.js` — `deleteProgressRecord()` success handler
- `issues-modal.js` — add issue success + edit issue success
- `risk-register.js` — add risk success + edit risk success + convert success

### Notification Regression Check

User reported risk level email + contractor inspection reminder regressions. Investigation confirmed the notification code is **byte-identical** between branch and develop — no code regression. Both fixes are intact:
- `SendNotifications.php` line 89: `$internalRecipients = $recipients->filter(fn ($u) => $u->role !== 'contractor')` — all inspection methods use `$internalRecipients`
- `ProjectProgressController.php`: RiskLevelChanged dispatch in all 4 locations intact

Issues resolved on their own (likely environment: queue worker, stale jobs).

### Pre-Session State

- All Bugs 1–10 + E1–E5 FIXED & TESTED (985 tests, 2 pre-existing EmailDraftTest failures)
- `fix/email-notification-delay` merged into `develop` (fast-forward, 120+ commits)
- `main` remains at `2767f7d` (unaffected)

---

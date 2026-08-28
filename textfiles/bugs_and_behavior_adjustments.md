# Bugs & Behavior Adjustments

**Date:** August 28, 2026
**Status:** Bug 7 pending — email notification delay (September 2026)

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
**Status:** IN PROGRESS — recording and planning phase.

### Problem

In-app notifications (database) appear instantly, but email notifications for all types are delayed by 30 to 60 minutes. This affects:
- `PlannedInspectionReminder`
- `MonthEndInspectionReminder`
- `OverdueInspectionReminder`
- `RiskLevelChanged`
- `DeadlineReminder`
- `ExtensionStatusChanged`
- `IssueReported`
- `RiskReported`

The delay is most noticeable when the `notifications:scan` command runs at 07:00 daily, or when a user manually triggers a risk level change by recording progress.

### Root Cause

Three compounding factors create a synchronous SMTP bottleneck:

**Factor 1 — No `ShouldQueue` on any notification class.**

Every notification class uses `via(['database', 'mail'])` and implements `toMail()`, but **none** implements `ShouldQueue`. The `Queueable` trait is imported on 7 of 8 classes but is inert without `ShouldQueue`. This means `$user->notify()` at `NotificationService.php:65` sends emails **synchronously** — it opens a blocking SMTP connection and waits for Gmail to respond before moving to the next recipient.

| Notification Class | File | `Queueable` trait | `ShouldQueue` |
|---|---|---|---|
| `PlannedInspectionReminder` | `app/Notifications/PlannedInspectionReminder.php:17` | YES | **NO** |
| `MonthEndInspectionReminder` | `app/Notifications/MonthEndInspectionReminder.php:21` | YES | **NO** |
| `OverdueInspectionReminder` | `app/Notifications/OverdueInspectionReminder.php:18` | YES | **NO** |
| `RiskLevelChanged` | `app/Notifications/RiskLevelChanged.php:18` | **NO** | **NO** |
| `DeadlineReminder` | `app/Notifications/DeadlineReminder.php:18` | YES | **NO** |
| `ExtensionStatusChanged` | `app/Notifications/ExtensionStatusChanged.php:18` | YES | **NO** |
| `IssueReported` | `app/Notifications/IssueReported.php:17` | YES | **NO** |
| `RiskReported` | `app/Notifications/RiskReported.php:18` | YES | **NO** |

**Factor 2 — Sequential per-project processing in `notifications:scan`.**

`SendNotifications::handle()` loops through ALL ongoing projects one at a time (`SendNotifications.php:71`). For each project, up to 5 notification types × 3 recipients each = sequential SMTP sends. With 50 ongoing projects, that's 750+ blocking SMTP calls in series.

```
SendNotifications::handle()                     [SendNotifications.php:37]
  └─ foreach $projects (ALL ongoing projects)   [line 71]
       ├─ sendDeadlineReminders()              → notifyMany()  → $user->notify() [BLOCKING]
       ├─ sendPlannedInspectionReminders()     → notifyMany()  → $user->notify() [BLOCKING]
       ├─ sendMonthEndInspectionReminders()    → notifyMany()  → $user->notify() [BLOCKING]
       ├─ sendOverdueInspectionReminders()     → notifyMany()  → $user->notify() [BLOCKING]
       └─ sendRiskLevelChangeNotifications()   → notifyMany()  → $user->notify() [BLOCKING]
```

**Factor 3 — Gmail SMTP rate limiting on free account.**

The system uses a free Gmail account (`projectmanagementunit57@gmail.com`). Gmail limits:
- ~500 emails/day max for free accounts
- ~100-200 rapid sends before throttling (returns `421`/`452` SMTP responses)
- `config/mail.php:48` sets `'timeout' => null` — no SMTP connection timeout, so slow connections hang indefinitely

### Code Path

**In-app notification (instant):**
```
NotificationService::notifyMany()  [NotificationService.php:78]
  └─ foreach $users
       └─ notifyUnique()           [line 42]
            └─ $user->notify()     [line 65]
                 └─ via(['database', 'mail'])
                      ├─ database: writes to notifications table (instant)
                      └─ mail: SMTP send (BLOCKING, 1-10+ seconds per email)
```

**Event-driven risk level change (same issue):**
`ProjectProgressController::recordMonthlyProgress()` dispatches `RiskLevelChanged` via the same `notifyMany()` path, blocking the HTTP request.

### Why Queue Is Configured But Unused

The queue infrastructure exists but is completely idle:
- `QUEUE_CONNECTION=database` in `.env` (line 54)
- `jobs` table exists (`database/migrations/2026_04_10_110748_create_jobs_table.php`)
- `composer dev` runs `queue:listen --tries=1 --timeout=0`
- `config/queue.php` default connection: `database`
- **But `app/Jobs/` directory does not exist** — zero job classes in the codebase
- No `ShouldQueue` on any notification or mailable class

### Fix Plan

Make notification classes queueable so emails are dispatched to the database queue and processed by the already-running `queue:listen` worker.

**Step 1 — Add `ShouldQueue` to all notification classes:**

Add `implements ShouldQueue` and ensure `Illuminate\Bus\Queueable` trait + `Illuminate\Contracts\Queue\ShouldQueue` import are present on all 8 notification classes.

**Step 2 — Add dedicated queue for emails:**

In `NotificationService::notifyMany()`, chain `->onQueue('emails')` before dispatch, so email jobs go to a dedicated queue separate from the default.

**Step 3 — Add error handling in `SendNotifications.php`:**

Wrap individual `notifyMany()` calls in try/catch so a single slow SMTP connection doesn't block the entire scan. Log failures and continue.

**Step 4 — Add `ShouldQueue` to Mailable classes (optional, consistency):**

Add `ShouldQueue` to `AiAnalysisMail`, `ContractorAccountMail`, `LoginPageLinkMail`.

### Files to Change

| File | Change |
|---|---|
| `app/Notifications/PlannedInspectionReminder.php` | Add `implements ShouldQueue`, ensure `Queueable` trait |
| `app/Notifications/MonthEndInspectionReminder.php` | Add `implements ShouldQueue`, ensure `Queueable` trait |
| `app/Notifications/OverdueInspectionReminder.php` | Add `implements ShouldQueue`, ensure `Queueable` trait |
| `app/Notifications/RiskLevelChanged.php` | Add `implements ShouldQueue`, add `Queueable` trait (missing) |
| `app/Notifications/DeadlineReminder.php` | Add `implements ShouldQueue`, ensure `Queueable` trait |
| `app/Notifications/ExtensionStatusChanged.php` | Add `implements ShouldQueue`, ensure `Queueable` trait |
| `app/Notifications/IssueReported.php` | Add `implements ShouldQueue`, ensure `Queueable` trait |
| `app/Notifications/RiskReported.php` | Add `implements ShouldQueue`, ensure `Queueable` trait |
| `app/Services/NotificationService.php` | Chain `->onQueue('emails')` on notify dispatch |
| `app/Console/Commands/SendNotifications.php` | Add try/catch around individual `notifyMany()` calls |

### Expected Behavior After Fix

| Scenario | Before Fix | After Fix |
|---|---|---|
| `notifications:scan` (50 projects) | 30-60 min blocking on SMTP | Completes in seconds; emails processed by queue worker |
| User records progress (risk level change) | HTTP request blocked 3-10 sec per email | HTTP request instant; emails queued |
| In-app notifications | Instant (database) | Instant (database, unchanged) |
| Email delivery | Delayed 30-60 min | Delivered within queue worker's processing cycle |

### Effort

Medium — 10 files, ~30-40 lines changed. No new files needed.

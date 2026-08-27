# Risk Label Alignment Plan (Option C)

## Problem

Three different risk classification systems exist across the app with inconsistent labels:

| System | Labels | Used By |
|--------|--------|---------|
| `ProjectStatusService` | On Track, Low Risk, Medium Risk, High Risk | Notification email, risk-level change notifications |
| `ProjectHealthService` | Low, Moderate, High, Critical | Dashboard health badge, AI prediction tab gauge |
| `EmailControllers` | On Track, Warning, Critical | Email detail viewers |

Users see different labels for the same project risk depending on where they look.

## Goal

Unify all risk labels to a single set while keeping the AI tab's current-health gauge as-is and adding a separate **Forecast Risk** indicator.

## Unified Label Set

| Internal Level | Display Label | Color |
|---------------|---------------|-------|
| `ahead` | Ahead of Schedule | green |
| `on_track` | On Track | green |
| `low_risk` | Low Risk | yellow |
| `medium_risk` | Medium Risk | orange |
| `high_risk` | High Risk | red |
| `overdue` | Overdue | red |
| `no_data` | No Data | gray |

**Source of truth:** `ProjectStatusService::getRiskLevel()` — already returns the correct labels. Everything else maps to these.

## Changes Required

### Phase 1: Align `ProjectHealthService` labels

**File:** `app/Services/ProjectHealthService.php`

1. **`classifyGap()` (lines 73-88):** Change return values from `Low`/`Moderate`/`High`/`Critical` to match `ProjectStatusService`:
   - `Low` → `low_risk`
   - `Moderate` → `medium_risk`
   - `High` → `high_risk`
   - `Critical` → `high_risk` (or keep a separate `critical_risk` if needed for escalation)

2. **`determine()` (line 191):** Update the `on_track` check from `$risk === 'Low'` to `$risk === 'low_risk'` (or `$risk === 'ahead' || $risk === 'on_track'`).

3. **`escalationReason()` (line 211):** When escalating from `on_track`, set `$risk = 'high_risk'` instead of `'High'`.

4. **`result()` (line 247):** Badge label now uses the unified labels: `ON TRACK` / `AT RISK` / `OVERDUE` / `NO DATA`. The `$risk` sub-label uses `Low Risk` / `Medium Risk` / `High Risk`.

### Phase 2: Align `AIPredictionPresenter` labels

**File:** `app/Services/AI/AIPredictionPresenter.php`

1. **Line 503:** `$currentGapRisk` now returns unified labels from `classifyGap()`.
2. **Line 518:** Update `$statusDisplay` mapping from `Low` → `low_risk`:
   ```php
   $statusDisplay = in_array($overallRisk, ['ahead', 'on_track']) ? 'ON TRACK' : 'AT RISK';
   ```
3. **Add `forecast_risk_level`** to the returned array — a separate risk level based on the **predicted future variance** from the regression forecast. This uses the same `ProjectStatusService::getRiskLevel()` thresholds but applied to the forecast month's predicted gap instead of the current gap.

### Phase 3: Add Forecast Risk indicator to AI Prediction tab

**File:** `resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php`

1. **Add a new row** below the existing "Schedule Status" row in the gauge info section (around line 135):
   ```blade
   <div class="metric-row">
       <span class="metric-label">Forecast Risk</span>
       <span class="metric-val" style="color: ...">
           {{ $prediction['forecast_risk_level'] ?? 'N/A' }}
       </span>
   </div>
   ```

2. **Color code** the forecast risk using `ProjectStatusService` colors (green/yellow/orange/red).

### Phase 4: Align EmailControllers

**Files:** `app/Http/Controllers/Admin/EmailController.php`, `staff/EmailController.php`, `contractor/EmailController.php`

Replace the `ok`/`warn`/`crit` → `On Track`/`Warning`/`Critical` mapping with `ProjectStatusService::getRiskLevel()`:
```php
$statusService = app(ProjectStatusService::class);
$riskData = $statusService->getRiskLevel(-abs($variance));
'risk_level' => $riskData['label'],  // "On Track", "Medium Risk", "High Risk"
```

Thresholds will now align: 5-10% behind = `Medium Risk`, 10-15% = `Medium Risk`, 15%+ = `High Risk`.

### Phase 5: Align `AiAnalysisMail` variance label

**File:** `app/Mail/AiAnalysisMail.php`

Replace the 3-tier hardcoded logic with `ProjectStatusService::getRiskLevel()`:
```php
$statusService = app(ProjectStatusService::class);
$riskData = $statusService->getRiskLevel(-abs($variance));
$this->varianceLabel = $riskData['label'];
$this->varianceColor = $riskData['color'] === 'green' ? '#10b981'
    : ($riskData['color'] === 'yellow' ? '#eab308'
    : ($riskData['color'] === 'orange' ? '#f97316' : '#ef4444'));
```

### Phase 6: Align email detail viewer JS banners

**Files:** `resources/views/admin/email.blade.php`, `resources/views/staff/email.blade.php`

Replace the `Critical`/`Warning`/`On Track` ternary with the `risk_level` value from the API (which now returns correct labels from Phase 4):
```javascript
const riskColor = m.risk_level.includes('High') || m.risk_level.includes('Critical') ? 'CRITICAL'
    : m.risk_level.includes('Medium') ? 'WARNING' : 'ON TRACK';
```

### Phase 7: Update `RiskLevelChanged` notification labels

**File:** `app/Notifications/RiskLevelChanged.php`

Update `isEscalation()` to use unified level keys:
```php
$order = ['ahead' => 0, 'on_track' => 1, 'low_risk' => 2, 'medium_risk' => 3, 'high_risk' => 4];
```

Update the `toMail()` display labels to show human-readable text (already done — `previousLevel`/`currentLevel` come from `ProjectStatusService` which already returns proper labels).

### Phase 8: Align notification list Blade title

**File:** `resources/views/notifications/_list.blade.php`

The `RiskLevelChanged` title already shows `previous_level → current_level`. After Phase 7, these will display as "On Track → Medium Risk" instead of the raw DB strings.

## Files Changed Summary

| File | Change |
|------|--------|
| `app/Services/ProjectHealthService.php` | Align `classifyGap()` labels to unified set |
| `app/Services/AI/AIPredictionPresenter.php` | Update status mapping, add `forecast_risk_level` |
| `app/Http/Controllers/Admin/EmailController.php` | Use `ProjectStatusService` for risk_level |
| `app/Http/Controllers/staff/EmailController.php` | Use `ProjectStatusService` for risk_level |
| `app/Http/Controllers/contractor/EmailController.php` | Use `ProjectStatusService` for risk_level |
| `app/Mail/AiAnalysisMail.php` | Use `ProjectStatusService` for variance label |
| `resources/views/admin/email.blade.php` | Update JS banner mapping |
| `resources/views/staff/email.blade.php` | Update JS banner mapping |
| `resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php` | Add Forecast Risk row |
| `app/Notifications/RiskLevelChanged.php` | Update level keys in `isEscalation()` |
| `resources/views/notifications/_list.blade.php` | Title shows unified labels |
| Tests | Update existing tests for new label values |

## What Stays the Same

- `ProjectStatusService::getRiskLevel()` — already correct, becomes the single source of truth
- AI tab status text (`ON TRACK` / `BEHIND SCHEDULE`) — this is schedule status, not risk level
- Progress tab variance labels (`On Track`, `Behind`, `Ahead`) — monthly variance, not overall risk
- Risk register levels (`Low`, `Medium`, `High`) — separate probability × impact scoring
- Issue severity (`Low`, `Medium`, `High`, `Critical`) — separate system
- Dashboard badge text (`ON TRACK` / `AT RISK` / `OVERDUE` / `NO DATA`) — binary status stays, sub-tier adds detail

## Testing Checklist

- [x] AI prediction tab gauge shows "Low Risk" / "Medium Risk" / "High Risk" (not "Low" / "Moderate")
- [x] AI prediction tab shows new "Forecast Risk" row based on predicted future gap
- [x] Email notification shows "Medium Risk" instead of "medium_risk"
- [x] Email detail banner shows correct labels (not "Warning")
- [x] Dashboard health badge still shows ON TRACK / AT RISK with correct sub-tier
- [x] Notification list page renders RiskLevelChanged with human-readable labels
- [x] Risk-level change notification email shows "On Track → Medium Risk"
- [x] All existing tests pass with updated label assertions
- [x] RiskLevelChanged notification is queued (implements ShouldQueue) — SMTP failure does not break progress save
- [x] Notification dispatch in ProjectProgressController is wrapped in try-catch — progress save succeeds even if notification fails

## Implementation Log

### Phase 1: ProjectHealthService (commit 2aaf7fe)
- `classifyGap()` returns `ahead`/`on_track`/`low_risk`/`medium_risk`/`high_risk` instead of `Low`/`Moderate`/`High`/`Critical`
- `determine()` updated: `in_array($risk, ['ahead', 'on_track'])` for on_track check, `$risk = 'high_risk'` for escalation, `$severity = $risk === 'high_risk'`
- Docblock updated to match unified thresholds

### Phase 2: AIPredictionPresenter (commit 2aaf7fe, 0043293, 11e65f5)
- Added `use App\Services\ProjectStatusService`
- Status display: `in_array($overallRisk, ['ahead', 'on_track'])` for ON TRACK
- Added `forecast_risk_level` computed from `nextPlanned - nextPredicted` gap via `ProjectStatusService::getRiskLevel()`
- Fixed: changed from `$forecastRiskData['label']` to `$forecastRiskData['level']` (Blade uses internal keys)
- Fixed: changed from `-abs($forecastGap)` to `$forecastGap` (getRiskLevel expects positive = behind)

### Phase 3: AI Prediction Tab Blade (commit 2aaf7fe)
- `$_riskColors` keyed by internal keys (`ahead`, `on_track`, `low_risk`, `medium_risk`, `high_risk`)
- Added `$_riskLabels` for display label mapping
- Added "Risk Level" row with unified display label
- Added "Forecast Risk" row (dashed border) with color from `$_riskColors`
- Gauge center text shows `$_currentRiskLabel` instead of raw `risk_level`

### Phase 4: EmailControllers (commit 2aaf7fe, 11e65f5)
- Admin, Staff, Contractor `EmailController` files: added `use ProjectStatusService`
- Replaced `$sev` ternary with `$statusService->getRiskLevel(-$variance)['label']`
- Fixed: changed from `-abs($variance)` to `-$variance` (getRiskLevel expects positive = behind)

### Phase 5: AiAnalysisMail (commit 2aaf7fe, 11e65f5)
- Added `use App\Services\ProjectStatusService`
- Replaced 3-tier if/elseif/else with `$statusService->getRiskLevel(-$variance)`
- Color mapped via match expression (`green`→`#10b981`, `yellow`→`#eab308`, `orange`→`#f97316`, default→`#ef4444`)
- Fixed: changed from `-abs($variance)` to `-$variance`

### Phase 6: Email Detail Viewer JS (commit 2aaf7fe)
- Admin and Staff `email.blade.php`: replaced `$riskData === 'Critical'` ternary with `risk_level.includes('High')` / `risk_level.includes('Medium')` mapping

### Phase 7: RiskLevelChanged Notification (commit 2aaf7fe)
- `isEscalation()` already used unified labels — no change needed

### Phase 8: Notification List Blade (commit 2aaf7fe)
- Already used `$d['previous_level']` / `$d['current_level']` from DB — labels now display correctly

### Phase 9: Controller stores display labels (commit 2aaf7fe)
- `ProjectProgressController::recordMonthlyProgress()`: changed `$riskData['level']` to `$riskData['label']` for notification and DB storage
- Backfill also uses `['label']`

### Phase 10: Make RiskLevelChanged queued (2026-08-27)
- **Problem:** `RiskLevelChanged` declared `['database', 'mail']` channels but did NOT implement `ShouldQueue`. When a risk-level change occurred during progress save, `$user->notify()` tried to send email synchronously via SMTP (`smtp.gmail.com:587`). If SMTP was unreachable (dev env, firewall), the entire progress save HTTP request failed with a 422 error.
- **Root cause:** The notification was designed as database-only (per the original notification system plan §3 rule 10: "No queue needed"), but when `['mail']` was added as a second channel, the synchronous execution model was not updated.
- **Fix applied:**
  1. `app/Notifications/RiskLevelChanged.php` — Added `implements ShouldQueue` (class now extends `Notification implements ShouldQueue`). Email delivery is dispatched to the queue worker instead of blocking the HTTP request.
  2. `app/Http/Controllers/ProjectProgressController.php` — Wrapped the entire notification dispatch block (lines 485–508) in `try { ... } catch (\Throwable $e)` with `\Log::warning(...)` so a notification failure never breaks the progress save response.
- **Why try-catch is still needed:** Even with `ShouldQueue`, the `notify()` call itself (which dispatches to the queue) can fail if the queue connection is down. The try-catch ensures the progress save is resilient regardless of queue/SMTP state.

### Bug Fixes (commit 11e65f5)
- Forecast Risk N/A: `$forecastRiskData['label']` → `$forecastRiskData['level']` (Blade keys by internal level)
- Forecast Risk wrong value: Changed from `$completion['predicted_delay_days']` to `nextPlanned - nextPredicted` gap
- Email risk levels inverted: `-abs($variance)` → `-$variance` in 3 EmailControllers + AiAnalysisMail
- Test updates: `ProjectHealthServiceTest` classifyGap assertions + determine risk assertions

### Tests
- 843 passed, 2 pre-existing `EmailDraftTest` failures (unrelated)
- Updated `ProjectHealthServiceTest`: classifyGap assertions + determine risk assertions
- Updated `NotificationTest`: risk-level stored values now display labels
- 14/14 `NotificationTest` pass (including 4 risk-level notification tests) after ShouldQueue fix

### Branch
- `ai/phase11b-risk-level-notification` (4 commits: `6fa1521`, `12d423f`, `2aaf7fe`, `0043293`, `11e65f5`)
- Ready to merge to `develop`

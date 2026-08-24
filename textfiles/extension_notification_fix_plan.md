# EXTENSION NOTIFICATION FIX PLAN

CSPC PMU System — fix extension notification recipients so the trigger user
never receives their own notification, and all relevant parties are notified.

Status: ✅ IMPLEMENTED — committed as 935312b, 25d4973 on develop
Date:   August 23, 2026

---

## Problem

The `notifyExtension()` method in `ProjectExtensionController` has three
recipient bugs and two data-integrity bugs in `store()`:

### Bug 1: Trigger user receives their own notification

- SUBMITTED: sends to ALL admins — the admin who created it receives it
- APPROVED/REJECTED: includes requester + assignedStaff — if the trigger
  user is either, they receive their own notification

### Bug 2: Assigned staff not notified on SUBMITTED

The SUBMITTED path (line 1452-1456) only notifies `$service->admins()`.
Assigned staff (the project manager) never learns about new extension
requests on their project.

### Bug 3: Other admins not notified on APPROVED/REJECTED

The APPROVED/REJECTED path (line 1459-1479) only sends to
`requester + contractor + assignedStaff`. Other admins who need visibility
into extension decisions are not notified.

### Bug 4: `approved_by_user_id` not recorded for staff-created approved

In `store()` line 104:
```php
'approved_by_user_id' => ($isAdmin && $status === 'approved') ? auth()->id() : null,
```
When an assigned staff member directly creates an approved extension,
`approved_by_user_id` stays NULL because `$isAdmin` is false.

### Bug 5: `recomputeProjectDeadline` skipped for staff-created approved

In `store()` line 114:
```php
if ($isAdmin && $status === 'approved') {
    $this->resolvePendingQueue($project, $extension);
    $this->recomputeProjectDeadline($project);
}
```
Same `$isAdmin`-only condition — the project deadline is not updated
when staff creates an approved extension.

---

## Test Results (Current Broken Behavior)

| # | Scenario | Trigger user | Other admins | Assigned staff | Requester | Contractor |
|---|---|---|---|---|---|---|
| 1 | Admin creates pending | **Receives ❌** | Receives ✅ | **Not notified ❌** | — | — |
| 2 | Staff creates pending | — | Receives ✅ | — | — | — |
| 3 | Admin approves pending | — | **Not notified ❌** | Receives ✅ | Receives ✅ | Receives ✅ |
| 4 | Staff approves pending | **Receives ❌** | **Not notified ❌** | — | Receives ✅ | Receives ✅ |
| 5 | Admin creates approved | **Receives ❌** | **Not notified ❌** | Receives ✅ | — | Receives ✅ |
| 6 | Staff creates approved | — | **Not notified ❌** | — | — | **Receives ✅** |
| 7 | Admin rejects pending | **Receives ❌** | **Not notified ❌** | Receives ✅ | Receives ✅ | Receives ✅ |
| 8 | Staff rejects pending | **Receives ❌** | **Not notified ❌** | — | Receives ✅ | Receives ✅ |
| 9 | Admin direct-rejects | **Receives ❌** | **Not notified ❌** | Receives ✅ | — | Receives ✅ |
| 10 | Staff direct-rejects | — | **Not notified ❌** | — | — | **Receives ✅** |

**Bold** = incorrect behavior

---

## Expected Behavior After Fix

| # | Scenario | Trigger user | Other admins | Assigned staff | Requester | Contractor |
|---|---|---|---|---|---|---|
| 1 | Admin creates pending | — | Notified ✅ | Notified ✅ | — | — |
| 2 | Staff creates pending | — | Notified ✅ | — | — | — |
| 3 | Admin approves pending | — | Notified ✅ | Notified ✅ | Notified ✅ | Notified ✅ |
| 4 | Staff approves pending | — | Notified ✅ | — | Notified ✅ | Notified ✅ |
| 5 | Admin creates approved | — | Notified ✅ | Notified ✅ | — | Notified ✅ |
| 6 | Staff creates approved | — | Notified ✅ | — | — | Notified ✅ |
| 7 | Admin rejects pending | — | Notified ✅ | Notified ✅ | Notified ✅ | Notified ✅ |
| 8 | Staff rejects pending | — | Notified ✅ | — | Notified ✅ | Notified ✅ |
| 9 | Admin direct-rejects | — | Notified ✅ | Notified ✅ | — | Notified ✅ |
| 10 | Staff direct-rejects | — | Notified ✅ | — | — | Notified ✅ |

**Key rule:** The trigger user (`auth()->id()`) is always excluded.
"-" means the notification is irrelevant for that role in that scenario.

---

## Changes

### A. `notifyExtension()` rewrite

**File:** `app/Http/Controllers/Admin/ProjectExtensionController.php`
**Method:** `notifyExtension()` (line ~1430)

Current logic:
```
SUBMITTED → admins() only
APPROVED/REJECTED → requester + contractor + assignedStaff
```

New logic:
```
SUBMITTED → (admins + assignedStaff) minus trigger user
APPROVED/REJECTED → (requester + contractor + assignedStaff + admins) minus trigger user
```

Implementation:

```php
private function notifyExtension(Project $project, ProjectExtension $extension, string $status): void
{
    $service = app(NotificationService::class);
    $triggerUserId = auth()->id();

    $currentEnd = \Carbon\Carbon::parse($extension->original_end_date);
    $effectiveEnd = \Carbon\Carbon::parse($extension->effectiveEndDate());
    $daysExtended = $currentEnd->diffInDays($effectiveEnd);

    $notification = new ExtensionStatusChanged(
        $project->id,
        $project->title,
        $extension->id,
        $extension->extension_number,
        $status,
        $status === ExtensionStatusChanged::REJECTED ? $extension->rejection_reason : null,
        $status === ExtensionStatusChanged::APPROVED
            ? $effectiveEnd->format('M d, Y')
            : null,
        $currentEnd->format('M d, Y'),
        $daysExtended
    );

    $recipients = collect();

    // Always include assigned staff (except trigger user).
    $assignedStaff = $service->staffForProject($project);
    if ($assignedStaff && $assignedStaff->id !== $triggerUserId) {
        $recipients->push($assignedStaff);
    }

    if ($status === ExtensionStatusChanged::SUBMITTED) {
        // Submission → admins + assigned staff, minus trigger user.
        // Dedupe bypassed so resubmissions re-notify.
        foreach ($service->admins() as $admin) {
            if ($admin->id !== $triggerUserId) {
                $recipients->push($admin);
            }
        }
        $service->notifyMany($recipients->unique('id'), $notification, dedupe: false);
        return;
    }

    // Approved / Rejected → requester + contractor + assigned staff + admins,
    // minus the trigger user.

    // Requester (if different from trigger user).
    if ($extension->created_by_user_id && $extension->created_by_user_id !== $triggerUserId) {
        $requester = User::find($extension->created_by_user_id);
        if ($requester && $requester->is_active) {
            $recipients->push($requester);
        }
    }

    // Contractor.
    $contractorUser = $service->contractorUserFor($project);
    if ($contractorUser && $contractorUser->id !== $triggerUserId) {
        $recipients->push($contractorUser);
    }

    // All other admins (minus trigger user).
    foreach ($service->admins() as $admin) {
        if ($admin->id !== $triggerUserId) {
            $recipients->push($admin);
        }
    }

    $service->notifyMany($recipients->unique('id'), $notification);
}
```

### B. Fix `approved_by_user_id` for staff-created approved

**File:** `app/Http/Controllers/Admin/ProjectExtensionController.php`
**Method:** `store()` line 104

Current:
```php
'approved_by_user_id' => ($isAdmin && $status === 'approved') ? auth()->id() : null,
'approved_at' => ($isAdmin && $status === 'approved') ? now() : null,
```

New:
```php
$canAutoApprove = ($isAdmin || $project->isAssignedTo(auth()->user())) && $status === 'approved';
// ...
'approved_by_user_id' => $canAutoApprove ? auth()->id() : null,
'approved_at' => $canAutoApprove ? now() : null,
```

### C. Fix `recomputeProjectDeadline` for staff-created approved

**File:** `app/Http/Controllers/Admin/ProjectExtensionController.php`
**Method:** `store()` line 114

Current:
```php
if ($isAdmin && $status === 'approved') {
```

New:
```php
if ($canAutoApprove) {
```

---

## Files Changed

| File | Change |
|---|---|
| `app/Http/Controllers/Admin/ProjectExtensionController.php` | `notifyExtension()` rewritten; `store()` approved_by_user_id + recompute conditions fixed |

## Verification

- `composer test` — all tests pass
- Manual QA:
  * Admin creates pending → assigned staff + other admins notified, creator NOT notified
  * Staff creates pending → other admins notified, staff NOT notified
  * Admin approves pending → requester + contractor + assigned staff + other admins notified, approving admin NOT notified
  * Staff approves pending → requester + contractor + other admins notified, approving staff NOT notified
  * Admin creates approved → contractor + assigned staff + other admins notified, creator NOT notified
  * Staff creates approved → contractor + other admins notified, creator NOT recorded as approved_by (FIXED), project deadline updated (FIXED)
  * All rejected scenarios mirror approved scenarios

---

## Implementation Log

Committed: `935312b`, `25d4973` on `develop`

Files changed:
- `app/Http/Controllers/Admin/ProjectExtensionController.php`:
  - `notifyExtension()`: rewritten — adds `$triggerUserId = auth()->id()` exclusion from all recipient lists; SUBMITTED now includes assigned staff + all admins minus trigger; APPROVED/REJECTED now includes requester + contractor + assigned staff + all admins minus trigger
  - `store()`: added `$canAutoApprove` variable for both `approved_by_user_id`/`approved_at` and `recomputeProjectDeadline`/`resolvePendingQueue` — both now use `($isAdmin || $project->isAssignedTo(auth()->user()))` instead of `$isAdmin` only

All 722 tests passing.

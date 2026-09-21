<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Notifications\ExtensionStatusChanged;
use App\Notifications\IssueReported;
use App\Notifications\OverdueInspectionReminder;
use App\Notifications\RiskLevelChanged;
use App\Notifications\RiskReported;
use App\Notifications\IssueAgingReminder;
use App\Notifications\PendingExtensionReminder;
use App\Notifications\ProjectNotStartedReminder;
use App\Notifications\ProjectUpdateNotice;
use App\Notifications\WarrantyReminder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * In-app notification helpers (gaps #12).
 *
 * Centralizes audience resolution and deduplication:
 *  - notifyUnique() writes a database notification only if no notification of
 *    the same class + recipient + tracking `key` already exists. The tracking
 *    key is read from the notification's toDatabase() payload (`data.key`), so
 *    daily scans escalate without re-firing and event-driven notices fire once.
 *  - notifyMany() fans out while still deduping per user, and records a single
 *    ActivityLog entry (admin active logs) whenever an admin was notified.
 *  - admins() / allStaff() / contractorUserFor() resolve notification audiences.
 *
 * The original eight classes route via ['database', 'mail'] (the email is QUEUED —
 * Concerns\QueuesMailOnly, 2026-09-21); the monitoring notices (InAppNotice
 * subclasses) are in-app only. Admins additionally see the event in the
 * Activity Logs.
 *
 * Decision 16: the ExtensionStatusChanged ActivityLog description omits the
 * rejection reason — the requester receives it via the notification + email +
 * the extension record; the shared feed keeps internal rationale out.
 */
class NotificationService
{
    /**
     * Deliver a notification to one user, skipping it if an identical
     * (same type + key) notification already exists for them.
     */
    public function notifyUnique(User $user, Notification $notification, bool $dedupe = true): bool
    {
        if (!$user->is_active) {
            return false;
        }

        $data = method_exists($notification, 'toDatabase')
            ? $notification->toDatabase($user)
            : $notification->toArray($user);

        $key = $data['key'] ?? null;

        if ($dedupe && $key !== null) {
            $exists = $user->notifications()
                ->where('type', get_class($notification))
                ->where('data->key', $key)
                ->exists();

            if ($exists) {
                return false;
            }
        }

        try {
            $user->notify($notification);
        } catch (\Exception $e) {
            \Log::warning('Notification delivery failed (mail channel may be unavailable): ' . $e->getMessage(), [
                'user_id' => $user->id,
                'notification' => get_class($notification),
            ]);
            // Database notification was still created — email failure should not block the process
        }
        return true;
    }

    /**
     * Deliver to many users, deduplicating per user.
     *
     * When an admin is among the delivered recipients, one ActivityLog entry is
     * written (admin active logs) summarizing the notification event.
     *
     * @param  iterable<User>  $users
     * @return int  number of notifications actually delivered
     */
    public function notifyMany(iterable $users, Notification $notification, bool $dedupe = true): int
    {
        $delivered = 0;
        $firstAdmin = null;

        foreach ($users as $user) {
            if ($this->notifyUnique($user, $notification, $dedupe)) {
                $delivered++;
                if ($firstAdmin === null && $user->role === 'admin') {
                    $firstAdmin = $user;
                }
            }
        }

        if ($firstAdmin !== null) {
            $this->logForAdmins($firstAdmin, $notification);
        }

        return $delivered;
    }

    /**
     * Record one admin ActivityLog entry for a notification event.
     */
    private function logForAdmins(User $admin, Notification $notification): void
    {
        $data = method_exists($notification, 'toDatabase')
            ? $notification->toDatabase($admin)
            : $notification->toArray($admin);

        [$action, $description] = $this->describeNotification($notification, $data);

        ActivityLog::create([
            'user_id'     => $admin->id,
            'project_id'  => $data['project_id'] ?? null,
            'action'      => $action,
            'description' => $description,
            'visibility'  => 'all',
        ]);
    }

    /**
     * Human-readable action + description for the admin Activity Logs page.
     *
     * @return array{0: string, 1: string}  [action, description]
     */
    private function describeNotification(Notification $notification, array $data): array
    {
        $project = $data['project_title'] ?? '';

        return match (get_class($notification)) {
            DeadlineReminder::class => [
                'Deadline Reminder',
                $data['bucket'] === 'overdue'
                    ? "System: Project \"{$project}\" is overdue (planned end {$data['planned_end_date']})."
                    : "System: Project \"{$project}\" deadline in {$data['days_remaining']} days (ends {$data['planned_end_date']}).",
            ],
            OverdueInspectionReminder::class => [
                'Overdue Inspection',
                ($data['month_count'] ?? 1) > 1
                    ? 'System: ' . $data['month_count'] . ' overdue inspections for "' . $project . '" — '
                        . implode(', ', array_map(fn ($m) => ($m['display'] ?? '') ?: ($m['label'] ?? ''), $data['months'] ?? [])) . '.'
                    : 'System: Overdue inspection for "' . $project . '" — ' . ($data['month_display'] ?? $data['month_label'] ?? '') . '.',
            ],
            // In-app monitoring notices (2026-09-21, restudy P-7..P-10)
            WarrantyReminder::class => [
                'Warranty Reminder',
                'System: Warranty for "' . $project . '" — ' . ($data['kind'] ?? '') . ' (' . ($data['date'] ?? '') . ').',
            ],
            ProjectNotStartedReminder::class => [
                'Not Yet Started',
                'System: Planned project "' . $project . '" has not started (planned start ' . ($data['planned_start_date'] ?? '') . ').',
            ],
            PendingExtensionReminder::class => [
                'Extension Pending',
                'System: Extension #' . ($data['extension_number'] ?? '') . ' for "' . $project . '" pending ' . ($data['days_pending'] ?? 0) . ' days.',
            ],
            IssueAgingReminder::class => [
                'Issue Aging',
                'System: Issue "' . ($data['issue_name'] ?? '') . '" on "' . $project . '" open ' . ($data['days_open'] ?? 0) . ' days.',
            ],
            ProjectUpdateNotice::class => [
                'Project Update',
                'System: ' . ($data['message'] ?? 'Project update') . ' — "' . $project . '".',
            ],
            ExtensionStatusChanged::class => [
                'Extension Update',
                'System: Extension ' . $data['status']
                    . ($data['extension_number'] ? ' #' . $data['extension_number'] : '')
                    . ' for "' . $project . '"'
                    . (!empty($data['actor_name']) ? ' by ' . $data['actor_name'] : '')
                    . '.',
            ],
            IssueReported::class => [
                'Issue Reported',
                'System: Issue "' . ($data['issue_name'] ?? '') . '" reported on "' . $project . '" (severity: ' . ($data['severity'] ?? '') . ').',
            ],
            RiskReported::class => [
                'High Risk Reported',
                'System: High risk "' . ($data['risk_name'] ?? '') . '" reported on "' . $project . '".',
            ],
            RiskLevelChanged::class => [
                'Risk Level Changed',
                'System: Risk level for "' . $project . '" changed from '
                    . ($data['previous_level'] ?? '') . ' to '
                    . ($data['current_level'] ?? '')
                    . ' (month: ' . ($data['month_display'] ?? $data['month_label'] ?? '') . ').',
            ],
            default => ['Notification', 'System notification for "' . $project . '".'],
        };
    }

    /**
     * All active admin users.
     */
    public function admins(): Collection
    {
        return User::where('role', 'admin')->where('is_active', true)->get();
    }

    /**
     * All active staff users.
     * @deprecated Use staffForProject() instead for scoped notifications.
     */
    public function allStaff(): Collection
    {
        return User::where('role', 'staff')->where('is_active', true)->get();
    }

    /**
     * The single assigned staff user for a project, if any.
     * Uses the User ID stored in assigned_project_manager.
     */
    public function staffForProject(Project $project): ?User
    {
        if (!$project->assigned_project_manager) {
            return null;
        }

        $userId = (int) $project->assigned_project_manager;

        $user = User::where('id', $userId)
            ->where('role', 'staff')
            ->where('is_active', true)
            ->first();

        return $user;
    }

    /**
     * Admins + the project's assigned staff — never the contractor. The audience
     * of every in-app-only monitoring / project-update notice (user decision
     * 2026-09-21, restudy N-D4). $except drops the person who caused the event
     * (nobody is told about their own action).
     * Called by: SendNotifications (scan) and the controllers that send
     * ProjectUpdateNotice.
     */
    public function internalRecipientsFor(?Project $project, ?User $except = null): Collection
    {
        $recipients = $this->admins();
        if ($project && ($staff = $this->staffForProject($project))) {
            $recipients->push($staff);
        }

        return $recipients
            ->unique('id')
            ->reject(fn (User $u) => $except && $u->id === $except->id)
            ->values();
    }

    /**
     * The active User account behind a project's contractor, if any.
     */
    public function contractorUserFor(Project $project): ?User
    {
        if (!$project->contractor) {
            return null;
        }

        $user = $project->contractor->user;

        return $user && $user->is_active ? $user : null;
    }
}

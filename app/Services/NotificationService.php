<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Notifications\ExtensionStatusChanged;
use App\Notifications\IssueReported;
use App\Notifications\OverdueInspectionReminder;
use App\Notifications\RiskReported;
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
 * Notification classes route via ['database', 'mail'], so every recipient also
 * receives an email; admins additionally see the event in the Activity Logs.
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

        $user->notify($notification);
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
                'System: Overdue inspection for "' . $project . '" — ' . ($data['month_display'] ?? $data['month_label'] ?? '') . '.',
            ],
            ExtensionStatusChanged::class => [
                'Extension Update',
                'System: Extension ' . $data['status']
                    . ($data['extension_number'] ? ' #' . $data['extension_number'] : '')
                    . ' for "' . $project . '"'
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
     */
    public function allStaff(): Collection
    {
        return User::where('role', 'staff')->where('is_active', true)->get();
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

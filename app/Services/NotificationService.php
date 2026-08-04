<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
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
 *  - admins() / allStaff() / contractorUserFor() resolve notification audiences.
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
     * @param  iterable<User>  $users
     * @return int  number of notifications actually delivered
     */
    public function notifyMany(iterable $users, Notification $notification, bool $dedupe = true): int
    {
        $delivered = 0;

        foreach ($users as $user) {
            if ($this->notifyUnique($user, $notification, $dedupe)) {
                $delivered++;
            }
        }

        return $delivered;
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

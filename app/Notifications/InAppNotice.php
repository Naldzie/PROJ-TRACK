<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuesMailOnly;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * ============================================================================
 * InAppNotice (base)
 * ============================================================================
 *
 * Base for notices that are IN-APP ONLY (no email) — user decision 2026-09-21
 * (textfiles/notification_monitoring_restudy_plan.md, N-D4): warranty,
 * not-yet-started, month-old extension, aging issue and the project-update
 * notices go to admins + assigned staff in the bell/inbox only.
 *
 * Keeps the same queue contract as every notification (ShouldQueue + Queueable
 * + QueuesMailOnly) so NotificationQueueContractTest covers it; with only the
 * 'database' channel it is written immediately (sync).
 *
 * Subclasses implement payload() (must include 'key' for dedupe and usually
 * 'project_id' / 'project_title').
 */
abstract class InAppNotice extends Notification implements ShouldQueue
{
    use Queueable, QueuesMailOnly;

    /** Database-only: no email for these notices. */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /** The stored data (key + fields the presenter reads). */
    abstract protected function payload(): array;

    public function toDatabase($notifiable): array
    {
        return $this->payload();
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}

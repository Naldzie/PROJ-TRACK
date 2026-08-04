<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Deadline reminder for a project nearing/overdue its planned_end_date.
 *
 * The data payload carries the tracking `key` used by NotificationService
 * to dedupe daily scans. `bucket` is one of '30' | '14' | '7' | 'overdue',
 * so each escalation step fires exactly once per project.
 */
class DeadlineReminder extends Notification
{
    use Queueable;

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public string $bucket,
        public int $daysRemaining,
        public string $plannedEndDate,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'key'             => "deadline:{$this->projectId}:{$this->bucket}",
            'project_id'      => $this->projectId,
            'project_title'   => $this->projectTitle,
            'bucket'          => $this->bucket,
            'days_remaining'  => $this->daysRemaining,
            'planned_end_date' => $this->plannedEndDate,
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}

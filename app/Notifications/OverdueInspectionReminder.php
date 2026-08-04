<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Overdue inspection alert for a baseline month whose planned_date has passed
 * with no recorded progress.
 *
 * Dedupe key: overdue_inspection:{project_id}:{month_label} — fires once per
 * (project, month) no matter how many daily scans run.
 */
class OverdueInspectionReminder extends Notification
{
    use Queueable;

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public string $monthLabel,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'key'           => "overdue_inspection:{$this->projectId}:{$this->monthLabel}",
            'project_id'    => $this->projectId,
            'project_title' => $this->projectTitle,
            'month_label'   => $this->monthLabel,
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}

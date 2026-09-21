<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Concerns\QueuesMailOnly;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Reminder sent on the baseline's planned inspection date.
 *
 * Fires once per (project, month) on the exact day the inspection is scheduled.
 * Dedupe key: planned_inspection:{project_id}:{month_label}
 */
class PlannedInspectionReminder extends Notification implements ShouldQueue
{
    // Email queued, in-app notice immediate — see Concerns\QueuesMailOnly
    use Queueable, QueuesMailOnly;

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public string $monthLabel,
        public string $monthDisplay = '',
        public string $plannedDate = '',
    ) {
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $month = $this->monthDisplay ?: $this->monthLabel;
        $title = 'Inspection Scheduled Today — ' . $this->projectTitle;

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifTitle'    => $title,
                'recipientName' => $notifiable->display_name ?? $notifiable->name ?? $notifiable->email,
                'intro'         => 'A scheduled inspection is due today for this project.',
                'lines'         => [
                    'Project'          => $this->projectTitle,
                    'Inspection month' => $month,
                    'Planned date'     => $this->plannedDate,
                    'Status'           => 'Due today',
                ],
            ]);
    }

    public function toDatabase($notifiable): array
    {
        return [
            'key'           => "planned_inspection:{$this->projectId}:{$this->monthLabel}",
            'project_id'    => $this->projectId,
            'project_title' => $this->projectTitle,
            'month_label'   => $this->monthLabel,
            'month_display' => $this->monthDisplay,
            'planned_date'  => $this->plannedDate,
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function url($notifiable): string
    {
        $route = match ($notifiable->role ?? 'admin') {
            'staff'      => 'staff.projects.detail',
            'contractor' => 'projects.show',
            default      => 'admin.projects.detail',
        };

        return route($route, $this->projectId) . '#baseline-schedule';
    }
}

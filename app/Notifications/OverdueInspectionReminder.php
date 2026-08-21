<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
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
        $title = 'Overdue Inspection — ' . $this->projectTitle;

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifTitle'    => $title,
                'recipientName' => $notifiable->display_name ?? $notifiable->name ?? $notifiable->email,
                'intro'         => 'A scheduled inspection month has passed without recorded progress for this project.',
                'lines'         => [
                    'Project'          => $this->projectTitle,
                    'Inspection month' => $month,
                    'Planned date'     => $this->plannedDate,
                    'Status'           => 'Overdue',
                ],
            ]);
    }

    public function toDatabase($notifiable): array
    {
        return [
            'key'           => "overdue_inspection:{$this->projectId}:{$this->monthLabel}",
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

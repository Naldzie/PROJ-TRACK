<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
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
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $overdue = $this->bucket === 'overdue';

        $title = $overdue
            ? 'Project Overdue — ' . $this->projectTitle
            : 'Deadline Reminder — ' . $this->projectTitle;

        $lines = [
            'Project'          => $this->projectTitle,
            'Planned end date' => $this->plannedEndDate,
        ];

        if ($overdue) {
            $lines['Status'] = 'Overdue by ' . abs($this->daysRemaining) . ' day(s)';
        } else {
            $lines['Days remaining'] = (string) $this->daysRemaining;
        }

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifTitle'    => $title,
                'recipientName' => $notifiable->display_name ?? $notifiable->name ?? $notifiable->email,
                'intro'         => $overdue
                    ? 'This project has passed its planned end date and is now overdue. Please review immediately.'
                    : "This project's deadline is approaching. Please ensure completion within the remaining time.",
                'lines'         => $lines,
            ]);
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

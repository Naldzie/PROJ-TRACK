<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Month-end inspection nudge — fires in the last 7 days of the month when
 * no progress has been recorded for the current month's baseline.
 *
 * Complements OverdueInspectionReminder by providing an early warning
 * before the month ends, giving staff a chance to inspect before the
 * overdue alert fires.
 *
 * Dedupe key: month_end_inspection:{project_id}:{month_label}
 */
class MonthEndInspectionReminder extends Notification
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
        $title = 'Month Ending Soon — ' . $this->projectTitle;

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifTitle'    => $title,
                'recipientName' => $notifiable->display_name ?? $notifiable->name ?? $notifiable->email,
                'intro'         => 'The month is almost over and no inspection has been recorded for this project.',
                'lines'         => [
                    'Project'          => $this->projectTitle,
                    'Inspection month' => $month,
                    'Planned date'     => $this->plannedDate,
                    'Status'           => 'Month ending — inspect soon',
                ],
            ]);
    }

    public function toDatabase($notifiable): array
    {
        return [
            'key'           => "month_end_inspection:{$this->projectId}:{$this->monthLabel}",
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

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Concerns\QueuesMailOnly;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Overdue inspection alert: baseline month(s) whose planned_date has passed
 * with no recorded progress.
 *
 * ONE notice per project (2026-09-21, restudy P-1): $months lists every
 * uninspected month; monthLabel / monthDisplay / plannedDate describe the
 * LATEST of them. Before, the scan sent one notice per month per person — a
 * project with 11 uninspected months sent 33 notices + 33 emails in one run.
 *
 * Dedupe key: overdue_inspection:{project_id}:{latest month_label} — once per
 * project until ANOTHER month becomes overdue, which re-alerts once with the
 * full list. (Older rows without 'months' still render as a single month.)
 */
class OverdueInspectionReminder extends Notification implements ShouldQueue
{
    // Email queued, in-app notice immediate — see Concerns\QueuesMailOnly
    use Queueable, QueuesMailOnly;

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public string $monthLabel,
        public string $monthDisplay = '',
        public string $plannedDate = '',
        public array $months = [],
    ) {
    }

    /** Human list of the overdue months (latest last); falls back to monthDisplay. */
    public function monthList(): string
    {
        $names = array_map(fn ($m) => $m['display'] ?: $m['label'], $this->months);

        return $names ? implode(', ', $names) : ($this->monthDisplay ?: $this->monthLabel);
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $count = max(count($this->months), 1);
        $title = ($count > 1 ? 'Overdue Inspections (' . $count . ' months) — ' : 'Overdue Inspection — ') . $this->projectTitle;

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifTitle'    => $title,
                'recipientName' => $notifiable->display_name ?? $notifiable->name ?? $notifiable->email,
                'intro'         => $count > 1
                    ? $count . ' scheduled inspection months have passed without recorded progress for this project.'
                    : 'A scheduled inspection month has passed without recorded progress for this project.',
                'lines'         => [
                    'Project'                                     => $this->projectTitle,
                    ($count > 1 ? 'Inspection months' : 'Inspection month') => $this->monthList(),
                    ($count > 1 ? 'Latest planned date' : 'Planned date') => $this->plannedDate,
                    'Status'                                      => 'Overdue',
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
            'months'        => $this->months,
            'month_count'   => max(count($this->months), 1),
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

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Project risk level changed — sent when the derived risk level shifts
 * (e.g., "On Track" → "Low Risk" or "Medium Risk" → "High Risk").
 *
 * Fires after each new progress recording if the variance-based risk level
 * differs from the previously stored level. Only fires on actual changes.
 *
 * Dedupe key: risk_level_change:{project_id}:{new_level}:{month_label}
 * — fires once per risk-level transition per month.
 */
class RiskLevelChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public string $previousLevel,
        public string $currentLevel,
        public string $monthLabel,
        public float $variance,
        public float $actualProgress = 0.0,
        public float $plannedProgress = 0.0,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $title = "Risk Level Changed — {$this->projectTitle}";
        $direction = $this->isEscalation() ? 'increased' : 'decreased';

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifTitle'    => $title,
                'recipientName' => $notifiable->display_name ?? $notifiable->name ?? $notifiable->email,
                'intro'         => "The project risk level has {$direction}. Please review the updated status.",
                'lines'         => [
                    'Project'        => $this->projectTitle,
                    'Previous'       => $this->previousLevel,
                    'Current'        => $this->currentLevel,
                    'Month'          => $this->formatMonth(),
                    'Planned'        => number_format($this->plannedProgress, 1) . '%',
                    'Actual'         => number_format($this->actualProgress, 1) . '%',
                    'Variance'       => number_format($this->variance, 2) . '%',
                ],
            ]);
    }

    public function toDatabase($notifiable): array
    {
        return [
            'key'              => "risk_level_change:{$this->projectId}:{$this->currentLevel}:{$this->monthLabel}",
            'project_id'       => $this->projectId,
            'project_title'    => $this->projectTitle,
            'previous_level'   => $this->previousLevel,
            'current_level'    => $this->currentLevel,
            'month_label'      => $this->monthLabel,
            'month_display'    => $this->formatMonth(),
            'actual_progress'  => $this->actualProgress,
            'planned_progress' => $this->plannedProgress,
            'variance'         => $this->variance,
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

        return route($route, $this->projectId) . '#overview';
    }

    /**
     * Determine if this change is an escalation (risk got worse).
     */
    private function isEscalation(): bool
    {
        $order = [
            'Ahead of Schedule' => 0,
            'On Track'          => 1,
            'Low Risk'          => 2,
            'Medium Risk'       => 3,
            'High Risk'         => 4,
        ];
        $prev = $order[$this->previousLevel] ?? 1;
        $curr = $order[$this->currentLevel] ?? 1;
        return $curr > $prev;
    }

    /**
     * Format month_label (e.g. "2026-08") as "August 2026".
     */
    private function formatMonth(): string
    {
        return \Carbon\Carbon::parse($this->monthLabel . '-01')->format('F Y');
    }
}

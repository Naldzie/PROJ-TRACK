<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Concerns\QueuesMailOnly;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * High-level risk reported on a project — sent to admins and the
 * project's contractor when a risk with level "High" is created.
 * Low/Medium risks are visible-only and do NOT fire this notification.
 *
 * Dedupe key: risk:{project_id}:{risk_id} — fires exactly once per risk.
 */
class RiskReported extends Notification implements ShouldQueue
{
    // Email queued, in-app notice immediate — see Concerns\QueuesMailOnly
    use Queueable, QueuesMailOnly;

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public int $riskId,
        public string $riskName,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $title = "High Risk Reported — {$this->projectTitle}";

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifTitle'    => $title,
                'recipientName' => $notifiable->display_name ?? $notifiable->name ?? $notifiable->email,
                'intro'         => 'A high-level risk has been reported on a project you are involved with. Please review immediately.',
                'lines'         => [
                    'Project'  => $this->projectTitle,
                    'Risk'     => $this->riskName,
                    'Severity' => 'High',
                    'Status'   => 'Open',
                ],
            ]);
    }

    public function toDatabase($notifiable): array
    {
        return [
            'key'           => "risk:{$this->projectId}:{$this->riskId}",
            'project_id'    => $this->projectId,
            'project_title' => $this->projectTitle,
            'risk_id'       => $this->riskId,
            'risk_name'     => $this->riskName,
            'severity'      => 'High',
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

        return route($route, $this->projectId) . '#risks';
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * High-level risk reported on a project — sent to admins and the
 * project's contractor when a risk with level "High" is created.
 * Low/Medium risks are visible-only and do NOT fire this notification.
 *
 * Dedupe key: risk:{project_id}:{risk_id} — fires exactly once per risk.
 */
class RiskReported extends Notification
{
    use Queueable;

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public int $riskId,
        public string $riskName,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
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
}

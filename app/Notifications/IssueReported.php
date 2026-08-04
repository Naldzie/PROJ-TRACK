<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * New issue reported on a project — sent to admins and the project's
 * contractor (all severities, per user confirmation).
 *
 * Dedupe key: issue:{project_id}:{issue_id} — fires exactly once per issue.
 */
class IssueReported extends Notification
{
    use Queueable;

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public int $issueId,
        public string $issueName,
        public string $severity,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'key'           => "issue:{$this->projectId}:{$this->issueId}",
            'project_id'    => $this->projectId,
            'project_title' => $this->projectTitle,
            'issue_id'      => $this->issueId,
            'issue_name'    => $this->issueName,
            'severity'      => $this->severity,
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}

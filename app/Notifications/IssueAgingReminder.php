<?php

namespace App\Notifications;

/**
 * An issue still unresolved 30+ days after it was reported (in-app only; admins +
 * assigned staff). The project page already flags aging issues; this brings it
 * to the people who can act without them opening the project.
 *
 * Dedupe key: issue_aging:{issue_id} — once per issue.
 */
class IssueAgingReminder extends InAppNotice
{
    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public int $issueId,
        public string $issueName,
        public string $severity,
        public int $daysOpen,
    ) {
    }

    protected function payload(): array
    {
        return [
            'key'           => "issue_aging:{$this->issueId}",
            'project_id'    => $this->projectId,
            'project_title' => $this->projectTitle,
            'issue_id'      => $this->issueId,
            'issue_name'    => $this->issueName,
            'severity'      => $this->severity,
            'days_open'     => $this->daysOpen,
        ];
    }
}

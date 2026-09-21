<?php

namespace App\Notifications;

/**
 * A "Planned" project whose planned start date has passed (in-app only; admins +
 * assigned staff). The daily scan used to skip Planned projects entirely.
 *
 * Dedupe key: not_started:{project_id}:{planned_start_date} — once per start date
 * (moving the start date re-alerts once).
 */
class ProjectNotStartedReminder extends InAppNotice
{
    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public string $plannedStartDate,
        public int $daysLate,
    ) {
    }

    protected function payload(): array
    {
        return [
            'key'                => "not_started:{$this->projectId}:{$this->plannedStartDate}",
            'project_id'         => $this->projectId,
            'project_title'      => $this->projectTitle,
            'planned_start_date' => $this->plannedStartDate,
            'days_late'          => $this->daysLate,
        ];
    }
}

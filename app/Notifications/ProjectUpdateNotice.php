<?php

namespace App\Notifications;

/**
 * Event notices that used to reach nobody (in-app only; admins + assigned staff,
 * never the person who did it) — user decision 2026-09-21, restudy F-11:
 *
 *   issue_resolved    an issue marked Resolved / Closed      (IssueController)
 *   ai_report_review  an AI report staged in the 5-day review window
 *                                                           (AIReportSendingService)
 *   project_assigned  a staff member or contractor assigned (ProjectController)
 *   baseline_revised  the original baseline edited / revised
 *                                                           (BaselineScheduleController)
 *
 * $message is the one-line headline shown in the bell / inbox; $detail an
 * optional second line; $ref makes the dedupe key unique per event.
 * Dedupe key: update:{kind}:{ref}.
 */
class ProjectUpdateNotice extends InAppNotice
{
    public const ISSUE_RESOLVED   = 'issue_resolved';
    public const AI_REPORT_REVIEW = 'ai_report_review';
    public const PROJECT_ASSIGNED = 'project_assigned';
    public const BASELINE_REVISED = 'baseline_revised';

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public string $kind,
        public string $message,
        public string $ref,
        public ?string $detail = null,
        public ?string $actorName = null,
    ) {
    }

    protected function payload(): array
    {
        return [
            'key'           => "update:{$this->kind}:{$this->ref}",
            'project_id'    => $this->projectId,
            'project_title' => $this->projectTitle,
            'kind'          => $this->kind,
            'message'       => $this->message,
            'detail'        => $this->detail,
            'actor_name'    => $this->actorName,
        ];
    }
}

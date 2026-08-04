<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Extension lifecycle notice — submitted (staff → admins), approved, or
 * rejected (admin → submitter + project contractor).
 *
 * Dedupe key includes status, so a staff resubmission (back to pending) and
 * a later approve/reject are distinct events, each firing exactly once.
 */
class ExtensionStatusChanged extends Notification
{
    use Queueable;

    public const SUBMITTED = 'submitted';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';

    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public int $extensionId,
        public int $extensionNumber,
        public string $status,
        public ?string $rejectionReason = null,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'key'              => "extension:{$this->projectId}:{$this->extensionId}:{$this->status}",
            'project_id'       => $this->projectId,
            'project_title'    => $this->projectTitle,
            'extension_id'     => $this->extensionId,
            'extension_number' => $this->extensionNumber,
            'status'           => $this->status,
            'rejection_reason' => $this->rejectionReason,
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}

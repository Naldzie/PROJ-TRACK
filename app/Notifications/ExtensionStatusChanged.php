<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
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
        public ?string $approvedEndDate = null,
        public ?string $currentEndDate = null,
        public ?int $daysExtended = null,
        // B1 — actor attribution + reviewed timestamp for email/database
        public ?string $actorName = null,
        public ?int $actorId = null,
        public ?string $reviewedAt = null,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $statusLabel = ucfirst($this->status);
        $title = "Extension {$statusLabel} — {$this->projectTitle}";

        $lines = [
            'Project'   => $this->projectTitle,
            'Extension' => $this->extensionNumber ? '#' . $this->extensionNumber : '—',
            'Status'    => $statusLabel,
        ];

        // B1 — actor attribution + reviewed timestamp (user confirmed 2026-09-02: include for approved/rejected)
        if ($this->status === self::APPROVED && $this->actorName) {
            $lines['Approved by'] = $this->actorName;
        }
        if ($this->status === self::REJECTED && $this->actorName) {
            $lines['Rejected by'] = $this->actorName;
        }
        if (in_array($this->status, [self::APPROVED, self::REJECTED], true) && $this->reviewedAt) {
            try {
                $lines['Reviewed on'] = \Carbon\Carbon::parse($this->reviewedAt)->format('M d, Y h:i A');
            } catch (\Throwable $e) {
                $lines['Reviewed on'] = $this->reviewedAt;
            }
        }

        if ($this->status === self::REJECTED) {
            $lines['Rejection reason'] = $this->rejectionReason ?? 'Not provided';
        }

        if ($this->status === self::APPROVED && $this->approvedEndDate) {
            $lines['New Deadline'] = $this->approvedEndDate;
            if ($this->currentEndDate) {
                $lines['Current End'] = $this->currentEndDate;
            }
            if ($this->daysExtended !== null) {
                $lines['Days Extended'] = $this->daysExtended . ' day(s)';
            }
        }

        $disclaimer = $this->status === self::APPROVED
            ? 'Please note that this extension may still be subject to change depending on project circumstances.'
            : null;

        return (new MailMessage)
            ->subject($title)
            ->view('emails.notification', [
                'notifTitle'    => $title,
                'recipientName' => $notifiable->display_name ?? $notifiable->name ?? $notifiable->email,
                'intro'         => match ($this->status) {
                    self::SUBMITTED => 'A new project extension request has been submitted and requires your review.',
                    self::APPROVED  => $this->actorName ? "Your project extension request has been approved by {$this->actorName}." : 'Your project extension request has been approved.',
                    self::REJECTED  => $this->actorName ? "Your project extension request was rejected by {$this->actorName}. See the reason below." : 'Your project extension request was rejected. See the reason below.',
                    default         => 'The status of a project extension request has changed.',
                },
                'lines'         => $lines,
                'disclaimer'    => $disclaimer,
            ]);
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
            'approved_end_date'=> $this->approvedEndDate,
            'current_end_date' => $this->currentEndDate,
            'days_extended'    => $this->daysExtended,
            // B1 — actor attribution for inbox filtering/history
            'actor_name'       => $this->actorName,
            'actor_id'         => $this->actorId,
            'reviewed_at'      => $this->reviewedAt,
            'reviewed_by_name' => $this->actorName,
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

        return route($route, $this->projectId) . '#extensions';
    }
}

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
                    self::APPROVED  => 'Your project extension request has been approved.',
                    self::REJECTED  => 'Your project extension request was rejected. See the reason below.',
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

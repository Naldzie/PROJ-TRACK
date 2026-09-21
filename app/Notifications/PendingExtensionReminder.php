<?php

namespace App\Notifications;

/**
 * An extension request still pending review after a month or more (in-app only;
 * admins + assigned staff). The submit-time notice stays as it was — a request
 * may be in process at another office — so this only nudges month-old ones
 * (user decision 2026-09-21).
 *
 * Dedupe key: pending_extension:{extension_id}:{months_pending} — once at 30
 * days, again at 60, 90, …
 */
class PendingExtensionReminder extends InAppNotice
{
    public function __construct(
        public int $projectId,
        public string $projectTitle,
        public int $extensionId,
        public int $extensionNumber,
        public int $daysPending,
        public string $filedDate,
    ) {
    }

    protected function payload(): array
    {
        $months = intdiv(max($this->daysPending, 0), 30);

        return [
            'key'              => "pending_extension:{$this->extensionId}:{$months}",
            'project_id'       => $this->projectId,
            'project_title'    => $this->projectTitle,
            'extension_id'     => $this->extensionId,
            'extension_number' => $this->extensionNumber,
            'days_pending'     => $this->daysPending,
            'filed_date'       => $this->filedDate,
        ];
    }
}

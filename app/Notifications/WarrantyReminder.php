<?php

namespace App\Notifications;

/**
 * A warranty that needs attention (in-app only; admins + assigned staff).
 * Kinds (App\Services\WarrantyMonitor): 'expiring' (end date within 30 days),
 * 'expired', 'mid_year' / 'final' (warranty inspection within 14 days).
 *
 * Dedupe key: warranty:{warranty_id}:{kind}:{date} — once per warranty, kind and
 * date (a changed date re-alerts once).
 */
class WarrantyReminder extends InAppNotice
{
    public function __construct(
        public int $warrantyId,
        public int $projectId,
        public string $projectTitle,
        public string $kind,
        public string $date,
        public int $days,
    ) {
    }

    protected function payload(): array
    {
        return [
            'key'           => "warranty:{$this->warrantyId}:{$this->kind}:{$this->date}",
            'warranty_id'   => $this->warrantyId,
            'project_id'    => $this->projectId,
            'project_title' => $this->projectTitle,
            'kind'          => $this->kind,
            'date'          => $this->date,
            'days'          => $this->days,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Warranty;
use App\Support\DateHelper;
use Carbon\Carbon;

/**
 * ============================================================================
 * WarrantyMonitor
 * ============================================================================
 *
 * Purpose
 * -------
 * One definition of "which warranty needs attention", used by BOTH the warranty
 * record page (warranty/preview.blade.php — the indicators) and the daily
 * notification scan (SendNotifications — WarrantyReminder), so the page and the
 * reminders can never disagree (textfiles/notification_monitoring_restudy_plan.md,
 * F-8 / P-7, user decision 2026-09-21).
 *
 * Checks, per warranty:
 *   expiry    — end_date: EXPIRED once past; EXPIRING when within
 *               EXPIRY_WARNING_DAYS.
 *   mid_year  — mid_year_inspection date: DUE when within
 *   final       INSPECTION_WARNING_DAYS or today; PASSED once the date is behind
 *               (the date may be when it was done — the page says "date passed",
 *               never "overdue", and no reminder is sent for a passed date).
 *
 * Dates are compared at DateHelper::now() (the dev Date Simulator applies).
 */
class WarrantyMonitor
{
    /** Days before the warranty end date that it counts as "expiring". */
    public const EXPIRY_WARNING_DAYS = 30;

    /** Days before a warranty inspection date that it counts as "due". */
    public const INSPECTION_WARNING_DAYS = 14;

    /**
     * ------------------------------------------------------------------------
     * Status of one warranty
     * ------------------------------------------------------------------------
     *
     * Returns ['expiry' => ?check, 'mid_year' => ?check, 'final' => ?check],
     * each check = ['state' => 'expired'|'expiring'|'due'|'passed'|'ok',
     *               'days' => int (signed, today → date), 'date' => 'Y-m-d',
     *               'label' => short human text]. Null when the date is not set.
     */
    public function statusFor(Warranty $warranty): array
    {
        $today = DateHelper::now()->copy()->startOfDay();

        return [
            'expiry'   => $this->expiryCheck($warranty->end_date, $today),
            'mid_year' => $this->inspectionCheck($warranty->mid_year_inspection, $today),
            'final'    => $this->inspectionCheck($warranty->final_inspection, $today),
        ];
    }

    /**
     * True when any check needs attention (expired / expiring / due).
     */
    public function needsAttention(array $status): bool
    {
        foreach ($status as $check) {
            if ($check && in_array($check['state'], ['expired', 'expiring', 'due'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Warranty end date → expired / expiring / ok.
     */
    private function expiryCheck($date, Carbon $today): ?array
    {
        if (!$date) {
            return null;
        }

        $d = Carbon::parse($date)->startOfDay();
        $days = (int) $today->diffInDays($d, false);

        if ($days < 0) {
            return ['state' => 'expired', 'days' => $days, 'date' => $d->toDateString(),
                'label' => 'Warranty expired ' . abs($days) . ' ' . (abs($days) === 1 ? 'day' : 'days') . ' ago'];
        }
        if ($days <= self::EXPIRY_WARNING_DAYS) {
            return ['state' => 'expiring', 'days' => $days, 'date' => $d->toDateString(),
                'label' => $days === 0 ? 'Warranty ends today' : 'Warranty ends in ' . $days . ' ' . ($days === 1 ? 'day' : 'days')];
        }

        return ['state' => 'ok', 'days' => $days, 'date' => $d->toDateString(), 'label' => ''];
    }

    /**
     * Warranty inspection date → due / passed / ok.
     */
    private function inspectionCheck($date, Carbon $today): ?array
    {
        if (!$date) {
            return null;
        }

        $d = Carbon::parse($date)->startOfDay();
        $days = (int) $today->diffInDays($d, false);

        if ($days < 0) {
            return ['state' => 'passed', 'days' => $days, 'date' => $d->toDateString(), 'label' => 'Date passed'];
        }
        if ($days <= self::INSPECTION_WARNING_DAYS) {
            return ['state' => 'due', 'days' => $days, 'date' => $d->toDateString(),
                'label' => $days === 0 ? 'Inspection today' : 'Inspection in ' . $days . ' ' . ($days === 1 ? 'day' : 'days')];
        }

        return ['state' => 'ok', 'days' => $days, 'date' => $d->toDateString(), 'label' => ''];
    }
}

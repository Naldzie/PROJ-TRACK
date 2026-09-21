<?php

namespace App\Services;

use App\Notifications\DeadlineReminder;
use App\Notifications\ExtensionStatusChanged;
use App\Notifications\IssueAgingReminder;
use App\Notifications\IssueReported;
use App\Notifications\MonthEndInspectionReminder;
use App\Notifications\OverdueInspectionReminder;
use App\Notifications\PendingExtensionReminder;
use App\Notifications\PlannedInspectionReminder;
use App\Notifications\ProjectNotStartedReminder;
use App\Notifications\ProjectUpdateNotice;
use App\Notifications\RiskLevelChanged;
use App\Notifications\RiskReported;
use App\Notifications\WarrantyReminder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * ============================================================================
 * NotificationPresenter
 * ============================================================================
 *
 * ONE place that turns a stored database notification into what the bell, the
 * inbox and the /notifications/recent JSON show (2026-09-21, restudy P-5/P-6 —
 * textfiles/notification_monitoring_restudy_plan.md). Before, the bell partial,
 * the inbox list and NotificationController each carried their own match() on
 * the class name, so a new type had to be added three times and the bell's
 * default arm labelled every unknown type "Issue reported".
 *
 * Called by: NotificationController (recent JSON + inbox filters),
 *            notifications/partials/bell.blade.php, notifications/_list.blade.php
 *
 * The wording of the eight original types is kept exactly as it was.
 */
class NotificationPresenter
{
    /**
     * Filterable types: class => [slug, human label]. The slug is what the inbox
     * type filter puts in the URL (?type=warranty).
     */
    public const TYPES = [
        DeadlineReminder::class           => ['deadline', 'Deadline Reminder'],
        PlannedInspectionReminder::class  => ['planned_inspection', 'Inspection Scheduled'],
        MonthEndInspectionReminder::class => ['month_end_inspection', 'Month-End Inspection'],
        OverdueInspectionReminder::class  => ['overdue_inspection', 'Overdue Inspection'],
        ExtensionStatusChanged::class     => ['extension', 'Extension Update'],
        PendingExtensionReminder::class   => ['pending_extension', 'Extension Pending'],
        IssueReported::class              => ['issue', 'Issue Reported'],
        IssueAgingReminder::class         => ['issue_aging', 'Issue Open 30+ Days'],
        RiskReported::class               => ['risk', 'Risk Reported'],
        RiskLevelChanged::class           => ['risk_level', 'Risk Level Changed'],
        WarrantyReminder::class           => ['warranty', 'Warranty'],
        ProjectNotStartedReminder::class  => ['not_started', 'Not Yet Started'],
        ProjectUpdateNotice::class        => ['update', 'Project Update'],
    ];

    /** slug => human label, for the inbox type filter. */
    public static function typeOptions(): array
    {
        $out = [];
        foreach (self::TYPES as [$slug, $label]) {
            $out[$slug] = $label;
        }

        return $out;
    }

    /** The notification class for a filter slug, or null when unknown. */
    public static function classForSlug(?string $slug): ?string
    {
        foreach (self::TYPES as $class => [$s]) {
            if ($s === $slug) {
                return $class;
            }
        }

        return null;
    }

    /** Human type label for a notification class. */
    public function typeLabel(string $class): string
    {
        return self::TYPES[$class][1] ?? 'Notification';
    }

    /**
     * ------------------------------------------------------------------------
     * Present one notification
     * ------------------------------------------------------------------------
     *
     * Returns: id, type (human label), label (short — bell), title (long — inbox),
     * meta (bell second line, without the time), details (inbox extra lines:
     * [text, color]), icon (bootstrap-icons class), tone (red|amber|violet|blue|
     * green), url (where a click goes; '' when none), read, created, created_at.
     */
    public function present(DatabaseNotification $n, string $role): array
    {
        $d = $n->data ?? [];
        $class = $n->type;
        $project = $d['project_title'] ?? '';
        $month = $d['month_display'] ?? $d['month_label'] ?? '';
        $monthCount = (int) ($d['month_count'] ?? 1);

        // Short bell label, long inbox title, icon, tone, project-page tab anchor
        [$label, $title, $icon, $tone, $anchor] = match ($class) {
            DeadlineReminder::class => ($d['bucket'] ?? '') === 'overdue'
                ? ['Project overdue', 'Project overdue — ' . $project, 'bi-clock', 'red', '#overview']
                : ['Deadline in ' . ($d['days_remaining'] ?? 0) . ' days', 'Deadline in ' . ($d['days_remaining'] ?? 0) . ' days — ' . $project, 'bi-clock', 'red', '#overview'],
            PlannedInspectionReminder::class => ['Inspection scheduled today', 'Inspection scheduled today — ' . $project . ' (' . $month . ')', 'bi-calendar-check', 'blue', '#baseline-schedule'],
            MonthEndInspectionReminder::class => ['Month ending — inspect now', 'Month ending soon — inspect ' . $project . ' (' . $month . ')', 'bi-calendar-event', 'amber', '#baseline-schedule'],
            OverdueInspectionReminder::class => $monthCount > 1
                ? ['Inspections overdue (' . $monthCount . ' months)', 'Inspections overdue — ' . $project . ' (' . $monthCount . ' months)', 'bi-calendar-x', 'amber', '#baseline-schedule']
                : ['Inspection overdue', 'Inspection overdue — ' . $project . ' (' . $month . ')', 'bi-calendar-x', 'amber', '#baseline-schedule'],
            ExtensionStatusChanged::class => ['Extension ' . ucfirst($d['status'] ?? ''), 'Extension ' . ucfirst($d['status'] ?? '') . ' #' . ($d['extension_number'] ?? '') . ' — ' . $project, 'bi-arrow-repeat', 'violet', '#extensions'],
            PendingExtensionReminder::class => ['Extension #' . ($d['extension_number'] ?? '') . ' pending ' . ($d['days_pending'] ?? 0) . ' days', 'Extension #' . ($d['extension_number'] ?? '') . ' still pending review (' . ($d['days_pending'] ?? 0) . ' days) — ' . $project, 'bi-hourglass-split', 'violet', '#extensions'],
            RiskLevelChanged::class => ['Risk level: ' . ($d['current_level'] ?? ''), 'Risk level changed — ' . $project . ' (' . ($d['previous_level'] ?? '') . ' → ' . ($d['current_level'] ?? '') . ')', 'bi-shield-exclamation', 'red', '#overview'],
            RiskReported::class => ['High risk reported', 'High risk: ' . ($d['risk_name'] ?? '') . ' — ' . $project, 'bi-exclamation-octagon', 'red', '#risks'],
            IssueAgingReminder::class => ['Issue open ' . ($d['days_open'] ?? 0) . ' days', 'Issue open ' . ($d['days_open'] ?? 0) . ' days — ' . ($d['issue_name'] ?? '') . ' on ' . $project, 'bi-exclamation-diamond', 'amber', '#issues'],
            WarrantyReminder::class => $this->warrantyText($d),
            ProjectNotStartedReminder::class => ['Not yet started', 'Not yet started — ' . $project . ' (start date passed ' . ($d['days_late'] ?? 0) . ' days ago)', 'bi-hourglass-top', 'amber', '#overview'],
            ProjectUpdateNotice::class => $this->updateText($d),
            // IssueReported and any unknown type
            default => ['Issue reported', 'Issue reported' . (($d['severity'] ?? '') ? ' (' . $d['severity'] . ')' : '') . ' — ' . ($d['issue_name'] ?? '') . ' on ' . $project, 'bi-exclamation-triangle', 'green', '#issues'],
        };

        // Bell meta: project, plus the month for the inspection types
        $meta = $project;
        if (in_array($class, [PlannedInspectionReminder::class, MonthEndInspectionReminder::class], true)
            || ($class === OverdueInspectionReminder::class && $monthCount <= 1)) {
            $meta .= ' · ' . $month;
        }

        return [
            'id'         => $n->id,
            'type'       => $this->typeLabel($class),
            'label'      => $label,
            'title'      => $title,
            'meta'       => $meta,
            'details'    => $this->details($class, $d),
            'icon'       => $icon,
            'tone'       => $tone,
            'url'        => $this->url($class, $d, $role, $anchor),
            'read'       => $n->read_at !== null,
            'created'    => $n->created_at?->diffForHumans(),
            'created_at' => $n->created_at?->format('M d, Y h:i A'),
        ];
    }

    /**
     * Warranty notice text by kind (expiring / expired / mid_year / final).
     */
    private function warrantyText(array $d): array
    {
        $project = $d['project_title'] ?? '';
        $days = (int) ($d['days'] ?? 0);
        $abs = abs($days);
        $inDays = $days === 0 ? 'today' : 'in ' . $abs . ' ' . ($abs === 1 ? 'day' : 'days');

        $label = match ($d['kind'] ?? '') {
            'expired'  => 'Warranty expired',
            'mid_year' => 'Mid-year warranty inspection ' . $inDays,
            'final'    => 'Final warranty inspection ' . $inDays,
            default    => $days === 0 ? 'Warranty ends today' : 'Warranty ends in ' . $abs . ' ' . ($abs === 1 ? 'day' : 'days'),
        };
        $tone = ($d['kind'] ?? '') === 'expired' ? 'red' : 'amber';

        return [$label, $label . ' — ' . $project, 'bi-shield-check', $tone, ''];
    }

    /**
     * Project-update notice text by kind (issue_resolved / ai_report_review /
     * project_assigned / baseline_revised).
     */
    private function updateText(array $d): array
    {
        $project = $d['project_title'] ?? '';
        $message = $d['message'] ?? 'Project update';

        [$icon, $tone, $anchor] = match ($d['kind'] ?? '') {
            ProjectUpdateNotice::ISSUE_RESOLVED   => ['bi-check-circle', 'green', '#issues'],
            ProjectUpdateNotice::AI_REPORT_REVIEW => ['bi-envelope-paper', 'blue', ''],
            ProjectUpdateNotice::PROJECT_ASSIGNED => ['bi-person-check', 'blue', '#overview'],
            ProjectUpdateNotice::BASELINE_REVISED => ['bi-graph-up', 'violet', '#s-curve-planning'],
            default                               => ['bi-info-circle', 'blue', '#overview'],
        };

        return [$message, $message . ($project ? ' — ' . $project : ''), $icon, $tone, $anchor];
    }

    /**
     * Extra inbox lines under the title: [text, css color].
     */
    private function details(string $class, array $d): array
    {
        $fmt = fn ($date) => Carbon::parse($date)->format('M d, Y');
        $out = [];

        switch ($class) {
            case DeadlineReminder::class:
                if (!empty($d['planned_end_date'])) {
                    $out[] = ($d['bucket'] ?? '') === 'overdue'
                        ? ['Due: ' . $fmt($d['planned_end_date']) . ' · Overdue by ' . abs($d['days_remaining'] ?? 0) . ' day(s)', '#b45309']
                        : ['Due: ' . $fmt($d['planned_end_date']) . ' · ' . ($d['days_remaining'] ?? 0) . ' day(s) remaining', '#374151'];
                }
                break;

            case OverdueInspectionReminder::class:
                if (!empty($d['months']) && count($d['months']) > 1) {
                    $names = array_map(fn ($m) => ($m['display'] ?? '') ?: ($m['label'] ?? ''), $d['months']);
                    $out[] = ['Uninspected months: ' . implode(', ', $names), '#b45309'];
                }
                if (!empty($d['planned_date'])) {
                    $out[] = [(($d['month_count'] ?? 1) > 1 ? 'Latest planned inspection: ' : 'Planned inspection: ') . $fmt($d['planned_date']), '#b45309'];
                }
                break;

            case PlannedInspectionReminder::class:
                if (!empty($d['planned_date'])) {
                    $out[] = ['Planned inspection: ' . $fmt($d['planned_date']), '#2563eb'];
                }
                break;

            case MonthEndInspectionReminder::class:
                if (!empty($d['planned_date'])) {
                    $out[] = ['Planned inspection: ' . $fmt($d['planned_date']), '#b45309'];
                }
                break;

            case ExtensionStatusChanged::class:
                // B1 — reviewer attribution + reviewed timestamp
                if (!empty($d['actor_name'])) {
                    $when = !empty($d['reviewed_at']) ? ' · ' . Carbon::parse($d['reviewed_at'])->format('M d, Y h:i A')
                        : (!empty($d['reviewed_by_name']) ? ' · ' . $d['reviewed_by_name'] : '');
                    $out[] = [ucfirst($d['status'] ?? 'Reviewed') . ' by ' . $d['actor_name'] . $when, '#475569'];
                }
                if (($d['status'] ?? '') === 'rejected' && !empty($d['rejection_reason'])) {
                    $out[] = ['Reason: ' . $d['rejection_reason'], '#b91c1c'];
                }
                if (($d['status'] ?? '') === 'approved') {
                    if (!empty($d['current_end_date'])) {
                        $out[] = ['Current end: ' . $d['current_end_date'], '#374151'];
                    }
                    if (!empty($d['approved_end_date'])) {
                        $out[] = ['New end: ' . $d['approved_end_date'], '#059669'];
                    }
                    if (!empty($d['days_extended'])) {
                        $out[] = ['Extended by ' . $d['days_extended'] . ' day(s)', '#6b7280'];
                    }
                }
                break;

            case RiskLevelChanged::class:
                if (isset($d['month_display'])) {
                    $out[] = ['Planned: ' . number_format($d['planned_progress'] ?? 0, 1) . '% · Actual: ' . number_format($d['actual_progress'] ?? 0, 1) . '% · Variance: ' . number_format($d['variance'] ?? 0, 2) . '%', '#374151'];
                }
                break;

            case PendingExtensionReminder::class:
                if (!empty($d['filed_date'])) {
                    $out[] = ['Filed ' . $fmt($d['filed_date']) . ' · still awaiting a decision', '#6d28d9'];
                }
                break;

            case IssueAgingReminder::class:
                $out[] = ['Severity: ' . (($d['severity'] ?? '') ?: '—') . ' · still unresolved', '#b45309'];
                break;

            case WarrantyReminder::class:
                if (!empty($d['date'])) {
                    $what = match ($d['kind'] ?? '') {
                        'mid_year' => 'Mid-year inspection date: ',
                        'final'    => 'Final inspection date: ',
                        default    => 'Warranty end date: ',
                    };
                    $out[] = [$what . $fmt($d['date']), '#b45309'];
                }
                break;

            case ProjectNotStartedReminder::class:
                if (!empty($d['planned_start_date'])) {
                    $out[] = ['Planned start: ' . $fmt($d['planned_start_date']) . ' · status still Planned', '#b45309'];
                }
                break;

            case ProjectUpdateNotice::class:
                if (!empty($d['detail'])) {
                    $out[] = [$d['detail'], '#374151'];
                }
                if (!empty($d['actor_name'])) {
                    $out[] = ['By ' . $d['actor_name'], '#6b7280'];
                }
                break;
        }

        return $out;
    }

    /**
     * Where a click on the notification goes: the project page tab for most
     * types; the warranty record page for warranties; the Email page for an AI
     * report entering review. '' when there is nowhere to go.
     */
    private function url(string $class, array $d, string $role, string $anchor): string
    {
        // Contractors have no warranty-preview or email-review route of this kind
        if ($class === WarrantyReminder::class && in_array($role, ['admin', 'staff'], true)) {
            return route($role . '.warranty.preview');
        }
        if ($class === ProjectUpdateNotice::class
            && ($d['kind'] ?? '') === ProjectUpdateNotice::AI_REPORT_REVIEW
            && in_array($role, ['admin', 'staff'], true)) {
            return route($role . '.email');
        }

        $pid = $d['project_id'] ?? null;
        if (!$pid) {
            return '';
        }

        $route = match ($role) {
            'staff'      => 'staff.projects.detail',
            'contractor' => 'projects.show',
            default      => 'admin.projects.detail',
        };

        return route($route, $pid) . ($anchor ?: '#overview');
    }
}

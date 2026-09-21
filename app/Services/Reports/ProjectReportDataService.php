<?php

namespace App\Services\Reports;

use App\Models\AiSolution;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectActivityCarryover;
use App\Models\ProjectExtension;
use App\Models\ProjectProgressHistory;
use App\Services\AI\AIPredictionPresenter;
use App\Services\AiReportParser;
use App\Services\ProjectDetailService;
use App\Services\ProjectStatusService;
use App\Support\DateHelper;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ============================================================================
 * ProjectReportDataService
 * ============================================================================
 *
 * Purpose
 * -------
 * Builds the CONTENT of the per-project "Project Monitoring Report" for one
 * reporting period (a month, 'Y-m') as a plain array. It decides WHAT the
 * report says; the renderers (ProjectReportDocxRenderer for Word, the
 * reports/project-report Blade + dompdf for PDF) only decide how it looks, so
 * the Word and PDF files can never disagree (textfiles/project_report_
 * overhaul_plan.md, decision R-D4).
 *
 * Reporting period ("as of")
 * --------------------------
 * The periods offered are the months that have an inspection record; the
 * default is the latest one (R-D2). Everything is cut at the END of the
 * period: inspections up to it, issues open at its end / resolved within it,
 * extensions filed by then (decisions made later read "Pending"), the actual
 * line of the S-curve, and the analysis that was current before the next
 * inspection. The forecast is shown only for the latest period — it is
 * computed as of today and would misdescribe an earlier month.
 *
 * Read-only: no writes, and no Gemini call (recommendations fall back to the
 * rule engine through the presenter, which never calls Gemini on render).
 *
 * Used By
 * -------
 * ReportExportService (Word + PDF project report)
 *
 * Depends On
 * ----------
 * ProjectDetailService (chart data), ProjectStatusService (risk ladder),
 * AIPredictionPresenter (forecast + fallback recommendations, latest period
 * only), AiReportParser (analysis sections as plain blocks)
 */
class ProjectReportDataService
{
    /**
     * AIPredictionPresenter output for the project being built (it runs the
     * whole regression, so it is computed at most once per build()).
     */
    private ?array $presented = null;

    public function __construct(
        private ProjectDetailService $detailService,
        private ProjectStatusService $statusService,
        private AiReportParser $parser
    ) {
    }

    /**
     * ------------------------------------------------------------------------
     * Available Periods
     * ------------------------------------------------------------------------
     *
     * Months that have an inspection record, newest first, as
     * [['value' => '2026-09', 'label' => 'September 2026'], …]. A project with
     * no inspection yet offers the current month only, so a report can still
     * be produced (it will say there are no inspections).
     *
     * Called by: build(), the report modal in the project header
     */
    public function availablePeriods(Project $project): array
    {
        $months = ProjectProgressHistory::where('project_id', $project->id)
            ->whereNotNull('month_label')
            ->distinct()
            ->orderBy('month_label', 'desc')
            ->pluck('month_label')
            ->filter(fn ($m) => (bool) preg_match('/^\d{4}-\d{2}$/', $m))
            ->values();

        if ($months->isEmpty()) {
            $months = collect([DateHelper::now()->format('Y-m')]);
        }

        return $months->map(fn ($m) => ['value' => $m, 'label' => $this->monthName($m)])->all();
    }

    /**
     * Validated period: the requested 'Y-m' when it is offered, else the latest.
     */
    public function resolvePeriod(Project $project, ?string $requested): string
    {
        $values = array_column($this->availablePeriods($project), 'value');

        return ($requested !== null && in_array($requested, $values, true)) ? $requested : $values[0];
    }

    /**
     * ------------------------------------------------------------------------
     * Build the Report
     * ------------------------------------------------------------------------
     *
     * Returns the whole report content for $project as of $period (null =
     * latest). Keys: meta, project, status, chart, progress, work, issues,
     * extensions, forecast, analysis, recommendations, closing.
     *
     * Called by: ReportExportService::projectReportDocx() / summaryPdf()
     */
    public function build(Project $project, ?string $period = null, ?string $generatedBy = null): array
    {
        $project->loadMissing(['category', 'budgetSource', 'contractor']);
        $this->presented = null;

        $periods = $this->availablePeriods($project);
        $period = $this->resolvePeriod($project, $period);
        $isLatest = $period === $periods[0]['value'];

        $periodStart = Carbon::createFromFormat('Y-m-d', $period . '-01')->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();

        // Inspections up to and including the period (oldest first)
        $inspections = ProjectProgressHistory::where('project_id', $project->id)
            ->where('month_label', '<=', $period)
            ->orderBy('month_label')
            ->orderBy('inspection_date')
            ->orderBy('created_at')
            ->get();
        $current = $inspections->where('month_label', $period)->last();

        // "As of" date: the period's inspection date, else the period end
        // (never later than today for the running month)
        $asOf = $current?->inspection_date?->copy()
            ?? Carbon::createFromTimestamp(min($periodEnd->getTimestamp(), DateHelper::now()->getTimestamp()));

        $extensions = $this->extensionsAsOf($project, $periodEnd);
        $revisedEnd = $this->revisedCompletion($project, $extensions, $isLatest);
        $issues = $this->issuesAsOf($project, $periodStart, $periodEnd);
        $aiSolution = $this->analysisFor($project, $period, $isLatest);

        return [
            'meta'            => $this->meta($project, $period, $periods, $isLatest, $asOf, $current, $generatedBy),
            'project'         => $this->projectInfo($project, $revisedEnd),
            'status'          => $this->statusAtGlance($project, $current, $asOf, $revisedEnd, $extensions, $issues),
            'chart'           => $this->chartAsOf($project, $periodEnd, $extensions, $isLatest),
            'progress'        => $this->progressRows($inspections),
            'work'            => $this->workAccomplished($project, $period),
            'issues'          => $issues,
            'extensions'      => $extensions['rows'],
            'forecast'        => $this->forecast($project, $isLatest),
            'analysis'        => $this->analysis($aiSolution),
            'recommendations' => $this->recommendations($project, $aiSolution, $isLatest),
            'closing'         => $this->closing($project, $period, $generatedBy),
        ];
    }

    /*
    |==========================================================================
    | Sections
    |==========================================================================
    */

    /**
     * Report identity: title, period, "as of" date, who generated it and when.
     */
    private function meta(Project $project, string $period, array $periods, bool $isLatest, Carbon $asOf, ?ProjectProgressHistory $current, ?string $generatedBy): array
    {
        return [
            'office'         => 'Camarines Sur Polytechnic Colleges — Project Management Unit',
            'title'          => 'Project Monitoring Report',
            'project_title'  => $project->title,
            'project_code'   => $project->code,
            'period'         => $period,
            'period_label'   => $this->monthName($period),
            'is_latest'      => $isLatest,
            'as_of'          => $this->date($asOf),
            'has_inspection' => $current !== null,
            'periods'        => $periods,
            'generated_at'   => DateHelper::now()->format('F j, Y g:i A'),
            'generated_by'   => $generatedBy,
        ];
    }

    /**
     * Section 1 — project information as ordered [label, value] rows.
     */
    private function projectInfo(Project $project, ?Carbon $revisedEnd): array
    {
        $contractor = $project->contractor;
        $originalEnd = $project->original_planned_end_date ?? $project->planned_end_date;

        $engineer = $project->getProjectManagerName();
        if ($engineer && $project->project_manager_contact) {
            $engineer .= ' (' . $project->project_manager_contact . ')';
        }

        $duration = $project->contract_duration_days ?? $project->planned_duration_days;

        $rows = [
            ['Project Title', $project->title],
            ['Project Code', $project->code],
            ['Location', $project->location],
            ['Category', $project->category?->name],
            ['Scope of Work', $project->description],
            ['Contractor', $contractor?->company_name],
            ['Contractor Representative', $contractor?->contractor_name],
            ['Contractor Contact', $contractor?->contact_number],
            ['Source of Funds', $project->budgetSource?->name],
            ['Approved Budget for the Contract (ABC)', $this->money($project->abc)],
            ['Contract Amount', $this->money($project->approved_budget)],
            ['Contract Duration', $duration ? number_format($duration) . ' calendar days' : null],
            ['Start Date', $this->date($project->planned_start_date)],
            ['Actual Start Date', $this->date($project->actual_start_date)],
            ['Original Completion Date', $this->date($originalEnd)],
            ['Revised Completion Date', $revisedEnd && $originalEnd && !$revisedEnd->isSameDay($originalEnd)
                ? $this->date($revisedEnd) . ' (after approved extensions)'
                : $this->date($revisedEnd)],
            ['Target Turnover Date', $this->date($project->planned_handing_over_date)],
            ['Project Engineer / In-charge', $engineer],
            ['Project Status', $project->status],
        ];

        // Actual completion only once the project is completed (the column
        // can hold an estimate on ongoing projects)
        if ($project->status === 'Completed' && $project->actual_end_date) {
            $rows[] = ['Actual Completion Date', $this->date($project->actual_end_date)];
        }

        return array_map(fn ($r) => ['label' => $r[0], 'value' => ($r[1] === null || $r[1] === '') ? '—' : (string) $r[1]], $rows);
    }

    /**
     * Section 2 — status at a glance, as of the period's inspection.
     * Uses the app-wide risk ladder (ProjectStatusService::getRiskLevel).
     */
    private function statusAtGlance(Project $project, ?ProjectProgressHistory $current, Carbon $asOf, ?Carbon $revisedEnd, array $extensions, array $issues): array
    {
        $planned = $current?->planned_progress_percent !== null ? (float) $current->planned_progress_percent : null;
        $actualRaw = $current?->actual_progress_percent ?? $current?->progress_percentage;
        $actual = $actualRaw !== null ? (float) $actualRaw : null;
        $slippage = ($planned !== null && $actual !== null) ? round($actual - $planned, 2) : null;

        // Schedule status from the gap; overdue when past the revised date
        // and not finished. No inspection → no status.
        $level = null;
        $label = 'No inspection recorded for this period';
        if ($slippage !== null) {
            $risk = $this->statusService->getRiskLevel($planned - $actual);
            $level = $risk['level'];
            $label = $risk['label'];
            if ($revisedEnd && $asOf->gt($revisedEnd) && $actual < 100) {
                $level = 'overdue';
                $label = 'Overdue';
            }
        }

        // Time elapsed vs work done — the plain "are we keeping pace" pair
        $start = $project->actual_start_date ?? $project->planned_start_date;
        $timeElapsed = null;
        $daysRemaining = null;
        if ($start && $revisedEnd && $revisedEnd->gt($start)) {
            $total = $start->diffInDays($revisedEnd);
            $timeElapsed = round(min(max($start->diffInDays($asOf, false) / $total * 100, 0), 100), 1);
            $daysRemaining = (int) floor($asOf->diffInDays($revisedEnd, false));
        }

        return [
            'planned'          => $planned,
            'actual'           => $actual,
            'slippage'         => $slippage,
            'level'            => $level,
            'label'            => $label,
            'time_elapsed'     => $timeElapsed,
            'days_remaining'   => $daysRemaining,
            'inspection_date'  => $this->date($current?->inspection_date),
            'extensions_count' => $extensions['approved_count'],
            'extension_days'   => $extensions['approved_days'],
            'open_issues'      => count($issues['open']),
        ];
    }

    /**
     * Section 3 — chart data with the actual line cut at the period end. The
     * latest period keeps the chart's own adjusted-plan flag (the project's
     * extension_count, exactly as the Progress tab); an earlier period hides
     * the adjusted plan when no extension had been approved by its end.
     */
    private function chartAsOf(Project $project, Carbon $periodEnd, array $extensions, bool $isLatest): array
    {
        $chart = $this->detailService->prepareChartData($project);
        $cutMs = $periodEnd->getTimestamp() * 1000;

        if (!empty($chart['actual_data'])) {
            $chart['actual_data'] = array_values(array_filter(
                $chart['actual_data'],
                fn ($pt) => !is_array($pt) || !isset($pt['x']) || (float) $pt['x'] <= $cutMs
            ));
        }

        if (!$isLatest && $extensions['approved_count'] === 0) {
            $chart['has_extension'] = false;
        }

        return $chart;
    }

    /**
     * Section 4 — every inspection up to the period (oldest first).
     */
    private function progressRows(Collection $inspections): array
    {
        return $inspections->map(function ($r) {
            $planned = $r->planned_progress_percent !== null ? (float) $r->planned_progress_percent : null;
            $actualRaw = $r->actual_progress_percent ?? $r->progress_percentage;
            $actual = $actualRaw !== null ? (float) $actualRaw : null;
            $variance = $r->variance_percent !== null
                ? (float) $r->variance_percent
                : (($planned !== null && $actual !== null) ? round($actual - $planned, 2) : null);

            return [
                'month'           => $this->monthName($r->month_label),
                'inspection_date' => $this->date($r->inspection_date),
                'planned'         => $planned,
                'actual'          => $actual,
                'slippage'        => $variance,
                'remarks'         => trim((string) $r->remarks) !== '' ? trim($r->remarks) : '—',
            ];
        })->values()->all();
    }

    /**
     * Section 5 — the period's activity checklist and what was carried into it.
     */
    private function workAccomplished(Project $project, string $period): array
    {
        $activities = ProjectActivity::where('project_id', $project->id)
            ->where('month_label', $period)
            ->orderBy('id')
            ->get()
            ->map(fn ($a) => [
                'name'        => $a->activity_name,
                'description' => $a->description,
                'done'        => (bool) $a->is_completed,
            ])->values()->all();

        $carried = ProjectActivityCarryover::with('activity')
            ->where('project_id', $project->id)
            ->where('to_month_label', $period)
            ->orderBy('id')
            ->get()
            ->map(function ($c) {
                return [
                    'name' => $c->activity?->activity_name ?? 'Activity #' . $c->activity_id,
                    'from' => $this->monthName($c->from_month_label),
                    'times' => (int) $c->carryover_number,
                ];
            })->values()->all();

        return [
            'activities' => $activities,
            'done_count' => count(array_filter($activities, fn ($a) => $a['done'])),
            'total'      => count($activities),
            'carried'    => $carried,
        ];
    }

    /**
     * Section 6 — issues open at the end of the period, and those resolved in it.
     * An issue resolved AFTER the period counts as open for this report.
     */
    private function issuesAsOf(Project $project, Carbon $periodStart, Carbon $periodEnd): array
    {
        $issues = Issue::where('project_id', $project->id)
            ->where('created_at', '<=', $periodEnd)
            ->orderBy('created_at')
            ->get();

        $open = [];
        $resolved = [];

        foreach ($issues as $issue) {
            $isResolvedStatus = in_array($issue->status, ['Resolved', 'Closed'], true);
            $resolvedAt = $isResolvedStatus ? ($issue->resolved_at ?? $issue->updated_at) : null;

            $row = [
                'name'        => $issue->issue_name,
                'description' => $issue->description ?: '—',
                'type'        => $issue->issue_type ? ucfirst($issue->issue_type) : '—',
                'severity'    => $issue->severity ?: '—',
                'reported'    => $this->date($issue->created_at),
                'status'      => $issue->status,
                'resolution'  => $issue->resolution_notes ?: '—',
                'resolved_on' => $this->date($resolvedAt),
            ];

            if ($resolvedAt === null || $resolvedAt->gt($periodEnd)) {
                // Still open at the end of this period
                $row['status'] = $isResolvedStatus ? 'Open' : $issue->status;
                $open[] = $row;
            } elseif ($resolvedAt->gte($periodStart)) {
                $resolved[] = $row;
            }
        }

        return ['open' => $open, 'resolved' => $resolved];
    }

    /**
     * Section 7 — extensions filed by the period end. A decision made after
     * the period reads "Pending"; approved totals count only decisions made
     * by then.
     */
    private function extensionsAsOf(Project $project, Carbon $periodEnd): array
    {
        $rows = [];
        $approvedCount = 0;
        $approvedDays = 0;
        $latestApprovedEnd = null;

        $extensions = ProjectExtension::where('project_id', $project->id)
            ->where('created_at', '<=', $periodEnd)
            ->orderBy('extension_number')
            ->get();

        foreach ($extensions as $ext) {
            $status = strtolower((string) $ext->status);
            $decidedAt = $ext->reviewed_at ?? $ext->approved_at;
            if (in_array($status, ['approved', 'rejected'], true) && $decidedAt && $decidedAt->gt($periodEnd)) {
                $status = 'pending';
            }

            $newEnd = $ext->approved_new_end_date ?? $ext->new_end_date;
            if ($status === 'approved') {
                $approvedCount++;
                $approvedDays += (int) $ext->duration_days;
                if ($newEnd && (!$latestApprovedEnd || $newEnd->gt($latestApprovedEnd))) {
                    $latestApprovedEnd = $newEnd->copy();
                }
            }

            $rows[] = [
                'number'   => $ext->extension_number,
                'days'     => (int) $ext->duration_days,
                'reason'   => $ext->reason ?: '—',
                'category' => $ext->reason_category ?: '—',
                'status'   => ucfirst($status),
                'filed'    => $this->date($ext->created_at),
                'decided'  => $status === 'pending' ? '—' : $this->date($decidedAt),
                'new_end'  => $this->date($newEnd),
            ];
        }

        return [
            'rows'                => $rows,
            'approved_count'      => $approvedCount,
            'approved_days'       => $approvedDays,
            'latest_approved_end' => $latestApprovedEnd,
        ];
    }

    /**
     * Completion date in force for the period: the project's current end date
     * for the latest period; for an earlier one, the last extension approved
     * by then, else the original date.
     */
    private function revisedCompletion(Project $project, array $extensions, bool $isLatest): ?Carbon
    {
        if ($isLatest && $project->planned_end_date) {
            return $project->planned_end_date->copy();
        }

        return $extensions['latest_approved_end']
            ?? ($project->original_planned_end_date ?? $project->planned_end_date)?->copy();
    }

    /**
     * Section 8 — plain-language forecast, latest period only. From the AI
     * presenter's regression outputs, worded for a non-technical reader (the
     * same rule the AI analysis follows since 2026-09-21).
     */
    private function forecast(Project $project, bool $isLatest): array
    {
        if (!$isLatest) {
            return ['available' => false, 'note' => 'The forecast appears only in the report for the latest period, because it is calculated from the latest data.'];
        }
        if ($project->status === 'Completed') {
            return ['available' => false, 'note' => 'The project is completed; no forecast is needed.'];
        }

        $p = $this->present($project);

        if (!empty($p['is_insufficient_data']) || !empty($p['is_category_baseline']) || empty($p['next_prediction'])) {
            return ['available' => false, 'note' => 'Not enough inspection records yet to forecast this project.'];
        }

        $lines = [];

        $next = (float) $p['next_prediction'];
        $low = $p['next_prediction_low'] ?? null;
        $high = $p['next_prediction_high'] ?? null;
        $range = ($low !== null && $high !== null)
            ? ' (likely between ' . $this->pct($low) . ' and ' . $this->pct($high) . ')'
            : '';
        $plan = $p['next_planned_progress'] ?? null;
        $lines[] = 'Next inspection: progress is expected to reach about ' . $this->pct($next) . $range
            . ($plan !== null ? ', against a planned ' . $this->pct($plan) . '.' : '.');

        if (!empty($p['can_estimate_completion']) && $p['estimated_completion'] instanceof \Carbon\CarbonInterface) {
            $delay = (int) ($p['predicted_delay_days'] ?? 0);
            $when = $delay > 0
                ? 'about ' . $delay . ' ' . ($delay === 1 ? 'day' : 'days') . ' after the planned completion date'
                : 'on or before the planned completion date';
            $lines[] = 'Expected completion: ' . $this->date($p['estimated_completion']) . ' — ' . $when . '.';
        } else {
            $lines[] = 'Expected completion: the current pace does not yet support a dependable completion date.';
        }

        $level = $p['completion_reliability']['level'] ?? $p['next_prediction_reliability']['level'] ?? null;
        $dependability = match ($level) {
            'High'   => 'The forecast is dependable — past forecasts for this project have been close to what was achieved.',
            'Medium' => 'The forecast is fairly dependable, but it may shift as new inspections come in.',
            'Low'    => 'The forecast is a rough estimate only; treat the dates as indicative.',
            default  => null,
        };
        if ($dependability) {
            $lines[] = $dependability;
        }

        if (!empty($p['trend_label'])) {
            $lines[] = 'Recent trend: ' . rtrim($p['trend_label'], '.') . '.';
        }

        return ['available' => true, 'lines' => $lines];
    }

    /**
     * The analysis current for the period: the latest one for the latest
     * period; otherwise the latest made before the NEXT inspection was
     * recorded (i.e. based on data up to this period).
     */
    private function analysisFor(Project $project, string $period, bool $isLatest): ?AiSolution
    {
        $query = $project->aiSolutions()->whereNotNull('analysis_text');

        if (!$isLatest) {
            $nextRecordedAt = ProjectProgressHistory::where('project_id', $project->id)
                ->where('month_label', '>', $period)
                ->min('created_at');
            if ($nextRecordedAt) {
                $query->where('created_at', '<', $nextRecordedAt);
            }
        }

        return $query->first();
    }

    /**
     * Section 9 — the stored analysis split into its sections (plain blocks).
     */
    private function analysis(?AiSolution $solution): array
    {
        if (!$solution || trim((string) $solution->analysis_text) === '') {
            return ['available' => false, 'sections' => [], 'generated_at' => null];
        }

        $titles = ['AI Comment' => 'Overall Comment'];
        $sections = array_map(function ($s) use ($titles) {
            $s['title'] = $titles[$s['title']] ?? $s['title'];
            return $s;
        }, $this->parser->parseToBlocks($solution->analysis_text));

        return [
            'available'    => !empty($sections),
            'sections'     => $sections,
            'generated_at' => $this->date($solution->created_at),
        ];
    }

    /**
     * Section 10 — recommendations stored with that analysis; for the latest
     * period without stored ones, the rule engine's (via the presenter,
     * which never calls Gemini on render).
     */
    private function recommendations(Project $project, ?AiSolution $solution, bool $isLatest): array
    {
        $recs = $solution?->recommendations ?? [];

        if (empty($recs) && $isLatest && $project->status !== 'Completed') {
            $recs = $this->present($project)['structured_recommendations'] ?? [];
        }

        return array_values(array_map(fn ($r) => [
            'category' => ucfirst($r['category'] ?? 'general'),
            'priority' => $r['priority'] ?? null,
            'action'   => $r['message'] ?? '',
            'reason'   => $r['reason'] ?? '',
            'source'   => !empty($r['ai_generated']) ? 'AI-assisted' : 'System rules',
        ], array_filter($recs, fn ($r) => is_array($r) && !empty($r['message']))));
    }

    /**
     * Closing statement — a system-generated report, no signatories (R-D6).
     */
    private function closing(Project $project, string $period, ?string $generatedBy): array
    {
        $by = $generatedBy ? ' by ' . $generatedBy : '';

        return [
            'statement' => 'This is a system-generated report of the CSPC PMU Project Monitoring System. '
                . 'It reflects the records in the system for ' . $this->monthName($period) . ' and does not require a signature.',
            'generated' => 'Generated' . $by . ' on ' . DateHelper::now()->format('F j, Y g:i A') . '.',
        ];
    }

    /**
     * AIPredictionPresenter::present() once per build (no Gemini call: the
     * presenter uses rule-based recommendations on render since 2026-09-21).
     */
    private function present(Project $project): array
    {
        return $this->presented ??= app(AIPredictionPresenter::class)->present($project);
    }

    /*
    |==========================================================================
    | Formatting helpers
    |==========================================================================
    */

    /**
     * 'Y-m' → 'September 2026' (other strings unchanged).
     */
    private function monthName(?string $monthLabel): string
    {
        if (!$monthLabel || !preg_match('/^\d{4}-\d{2}$/', $monthLabel)) {
            return (string) $monthLabel;
        }

        return Carbon::createFromFormat('Y-m-d', $monthLabel . '-01')->format('F Y');
    }

    /**
     * Carbon|string|null → 'September 15, 2026' (null stays null).
     */
    private function date($value): ?string
    {
        if (!$value) {
            return null;
        }

        return ($value instanceof Carbon ? $value : Carbon::parse($value))->format('F j, Y');
    }

    /**
     * Amount → '₱ 10,000,000.00' (null stays null).
     */
    private function money($amount): ?string
    {
        return $amount === null || $amount === '' ? null : '₱ ' . number_format((float) $amount, 2);
    }

    /**
     * 47.6 → '47.6%' (drops a trailing .0).
     */
    private function pct($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
    }
}

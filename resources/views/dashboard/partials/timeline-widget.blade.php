{{-- ═══════════════════════════════════════════════════════════════════
     TIMELINE WIDGET — deadline-first hero strip (admin-first view)
     Used by: resources/views/admin/dashboard.blade.php (tab-timeline pane)
     JS:      public/js/dashboard-timeline.js (range toggle + tooltip + filter)
     CSS:     public/css/dashboard/timeline.css (flat navy/teal, hairline only)

     Variables received (all from DashboardController@index):
       $timelineProjects      — all ongoing, non-archived, sorted by deadline
       $timelineProgress       — getProgressData() maps for those projects
       $timelineQuarter/Year   — ['start' => Y-m-d, 'end' => Y-m-d] ranges
       $totalProjects, $ongoingCount, $overdueCount, $extensionSummary
       $projectsIndex          — role-aware projects route name (parent view)

     Notes:
       • Health + planned/actual come from ProjectHealthService::determine(),
         the same call the Overview table makes — badge colour and tooltip
         can never disagree with the table.
       • Bar geometry is day-proportional within the active range; clamped so
         overdue (fully left of range) and future (fully right) projects pin
         as a sliver at the edge instead of vanishing.
       • Pending-extension badge uses the pre-fetched pendingExtensionCounts
         map — zero extra queries per row.
       • The Extensions KPI toggles an in-place "extension rows only" filter
         (there is no global extensions page to link to); the other three
         KPIs deep-link into the projects list like the Overview cards do.
     Sentence case throughout per design spec — no uppercase transforms here.
════════════════════════════════════════════════════════════════════════ --}}
@php
    use Carbon\Carbon;

    // ── Active range defaults to the full calendar year ──────────────────
    $tlRangeStart = Carbon::parse($timelineYear['start'])->startOfDay();
    $tlRangeEnd   = Carbon::parse($timelineYear['end'])->endOfDay();
    $tlRangeDays  = max(1, $tlRangeStart->diffInDays($tlRangeEnd) + 1);
    $tlToday      = now();
    $tlTodayPct   = ($tlToday->between($tlRangeStart, $tlRangeEnd))
        ? round($tlRangeStart->diffInDays($tlToday) / $tlRangeDays * 100, 2)
        : null;

    // ── Month segments for the header (day-proportional widths) ──────────
    $tlMonths = [];
    $cursor = $tlRangeStart->copy()->startOfMonth();
    while ($cursor->lte($tlRangeEnd)) {
        $segStart = $cursor->copy()->max($tlRangeStart);
        $segEnd   = $cursor->copy()->endOfMonth()->min($tlRangeEnd);
        $tlMonths[] = [
            'label' => $cursor->format('M'),
            'width' => round(($segStart->diffInDays($segEnd) + 1) / $tlRangeDays * 100, 2),
            // Month-boundary gridline position (first month starts at 0 — no line).
            'line'  => round($tlRangeStart->diffInDays($segStart) / $tlRangeDays * 100, 2),
        ];
        $cursor->addMonthNoOverflow()->startOfMonth();
    }

    $tlQuarterTitle = 'This year';
    $tlQuarterSub   = $tlRangeStart->format('M') . ' 1 – ' . $tlRangeEnd->format('M j, Y');

    // ── Unpack the pre-fetched progress maps for row-level lookups ────────
    $tlLatest   = $timelineProgress['latestKnownProgress'] ?? collect();
    $tlMonthRec = $timelineProgress['currentMonthProgress'] ?? collect();
    $tlMonthBase= $timelineProgress['currentMonthBaseline'] ?? collect();
    $tlBaselines= $timelineProgress['baselineSchedules'] ?? collect();
    $tlOpenHigh = $timelineProgress['openHighIssueCounts'] ?? collect();
    $tlAging    = $timelineProgress['agingIssueCounts'] ?? collect();
    $tlHighRisk = $timelineProgress['openHighRiskCounts'] ?? collect();
    $tlPendExt  = $timelineProgress['pendingExtensionCounts'] ?? collect();
@endphp

<div class="tl-wrap"
     data-quarter-start="{{ $timelineQuarter['start'] }}"
     data-quarter-end="{{ $timelineQuarter['end'] }}"
     data-year-start="{{ $timelineYear['start'] }}"
     data-year-end="{{ $timelineYear['end'] }}">

    {{-- ── Header: title + range toggle + actions ───────────────────────── --}}
    <div class="tl-head">
        <div class="tl-head-text">
            <h3 class="tl-title" id="tlTitle">{{ $tlQuarterTitle }}</h3>
            <p class="tl-sub">
                <span id="tlRange">{{ $tlQuarterSub }}</span>
                <span class="tl-sub-sep">·</span>
                <span>All active projects by deadline, soonest first.</span>
            </p>
        </div>
        <div class="tl-head-actions">
            <div class="tl-toggle" role="group" aria-label="Timeline range">
                <button type="button" class="tl-toggle-btn" data-range="quarter">This quarter</button>
                <button type="button" class="tl-toggle-btn active" data-range="year">This year</button>
            </div>
            <a href="{{ route($projectsIndex) }}" class="tl-btn primary">
                <i class="bi bi-plus-circle"></i> New project
            </a>
            <a href="{{ route($projectsIndex) }}" class="tl-btn">
                <i class="bi bi-folder2-open"></i> All projects
            </a>
        </div>
    </div>

    {{-- ── Legend (flat dots, sentence case) ────────────────────────────── --}}
    <div class="tl-legend">
        <span class="tl-legend-item"><span class="tl-dot is-ontrack"></span> On track</span>
        <span class="tl-legend-item"><span class="tl-dot is-atrisk"></span> At risk</span>
        <span class="tl-legend-item"><span class="tl-dot is-overdue"></span> Overdue</span>
        <span class="tl-legend-item"><span class="tl-dot is-ext"></span> Extension requested</span>
    </div>

    {{-- ── Timeline chart (hero) ────────────────────────────────────────── --}}
    <div class="tl-chart">
        {{-- Month labels + hairline divider --}}
        <div class="tl-months">
            <div class="tl-name-col"></div>
            <div class="tl-months-track" id="tlMonths">
                @foreach($tlMonths as $m)
                    <span class="tl-month" style="width:{{ $m['width'] }}%">{{ $m['label'] }}</span>
                @endforeach
            </div>
            <div class="tl-deadline-col"></div>
        </div>

        <div class="tl-rows" id="tlRows">
            @forelse($timelineProjects as $project)
                @php
                    // ── Health (same service + inputs as the Overview table) ──
                    $tlLatestRec = $tlLatest[$project->id] ?? null;
                    $tlMonthHistory = $tlMonthRec[$project->id] ?? null;
                    $tlMonthBaseline = $tlMonthBase[$project->id] ?? null;
                    $health = app(\App\Services\ProjectHealthService::class)->determine($project, [
                        'latest_progress'        => $tlLatestRec,
                        'current_month_history'  => $tlMonthHistory,
                        'current_month_baseline' => $tlMonthBaseline,
                        'baseline_schedules'     => $tlBaselines[$project->id] ?? collect(),
                        'open_high_issues'       => (int) ($tlOpenHigh[$project->id] ?? 0) > 0,
                        'aging_issue_count'      => (int) ($tlAging[$project->id] ?? 0),
                        'open_high_risks'        => (int) ($tlHighRisk[$project->id] ?? 0) > 0,
                        'pending_extension'      => (int) ($tlPendExt[$project->id] ?? 0) > 0,
                    ]);

                    // Bar colour + deadline text colour follow health level.
                    $barClass = match ($health['level']) {
                        'overdue'  => 'is-overdue',
                        'at_risk'  => 'is-atrisk',
                        'on_track' => 'is-ontrack',
                        default    => 'is-nodata',
                    };
                    $deadlineClass = match ($health['level']) {
                        'overdue' => 'is-overdue',
                        'at_risk' => 'is-atrisk',
                        default   => 'is-neutral',
                    };

                    $hasPendingExt = (int) ($tlPendExt[$project->id] ?? 0) > 0;
                    $planned = round((float) ($health['expected'] ?? 0));
                    $actual  = round((float) ($health['actual'] ?? 0));

                    // ── Day-proportional bar geometry, clamped to range ────
                    $pStart = $project->planned_start_date ? Carbon::parse($project->planned_start_date)->startOfDay() : null;
                    $pEnd   = $project->planned_end_date ? Carbon::parse($project->planned_end_date)->startOfDay() : null;
                    if ($pEnd && $pEnd->lt($tlRangeStart)) {
                        $tlLeft = 0; $tlWidth = 2; // Overdue: pin a sliver at the left edge.
                    } elseif ($pStart && $pStart->gt($tlRangeEnd)) {
                        $tlLeft = 98; $tlWidth = 2; // Future: pin a sliver at the right edge.
                    } else {
                        $barStart = $pStart ? $pStart->max($tlRangeStart) : $tlRangeStart;
                        $barEnd   = $pEnd ? $pEnd->min($tlRangeEnd) : $tlRangeEnd;
                        $tlLeft  = round($tlRangeStart->diffInDays($barStart) / $tlRangeDays * 100, 2);
                        $tlWidth = max(2, round(($barStart->diffInDays($barEnd) + 1) / $tlRangeDays * 100, 2));
                    }

                    $detailRoute = auth()->user()->role === 'staff'
                        ? route('staff.projects.detail', $project->id)
                        : route('admin.projects.detail', $project->id);
                @endphp
                <a href="{{ $detailRoute }}" class="tl-row" data-has-ext="{{ $hasPendingExt ? '1' : '0' }}">
                    <div class="tl-name">
                        <span class="tl-proj">{{ $project->title }}</span>
                        @if($hasPendingExt)
                            <span class="tl-ext-badge" title="Extension requested — deadline under review">
                                <i class="bi bi-arrow-repeat"></i>
                            </span>
                        @endif
                        <span class="tl-code">{{ $project->code }}</span>
                    </div>
                    <div class="tl-track">
                        <div class="tl-grid">
                            @foreach($tlMonths as $m)
                                @if($m['line'] > 0)
                                    <span class="tl-gridline" style="left:{{ $m['line'] }}%"></span>
                                @endif
                            @endforeach
                        </div>
                        @if($tlTodayPct !== null)
                            <span class="tl-today" style="left:{{ $tlTodayPct }}%"></span>
                        @endif
                        <span class="tl-bar {{ $barClass }}"
                              style="left:{{ $tlLeft }}%;width:{{ $tlWidth }}%"
                              data-start="{{ $pStart?->toDateString() ?? '' }}"
                              data-end="{{ $pEnd?->toDateString() ?? '' }}"
                              data-planned="{{ $planned }}"
                              data-actual="{{ $actual }}"
                              data-title="{{ $project->title }}"
                              aria-label="{{ $project->title }} — planned {{ $planned }}%, actual {{ $actual }}%"></span>
                    </div>
                    <div class="tl-deadline {{ $deadlineClass }}">
                        {{ $pEnd?->format('M j, Y') ?? 'No date' }}
                    </div>
                </a>
            @empty
                <div class="tl-empty">
                    <i class="bi bi-calendar-check"></i>
                    <div class="tl-empty-title">No active projects right now</div>
                    <div>Projects that are being worked on will appear here by deadline.</div>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ── KPI strip (tinted blocks, no borders) ────────────────────────── --}}
    <div class="tl-kpis">
        <a href="{{ route($projectsIndex) }}" class="tl-kpi k-total">
            <span class="tl-kpi-label">Total projects</span>
            <span class="tl-kpi-num">{{ $totalProjects }}</span>
        </a>
        <a href="{{ route($projectsIndex, ['status' => 'Ongoing']) }}" class="tl-kpi k-ongoing">
            <span class="tl-kpi-label">Ongoing</span>
            <span class="tl-kpi-num">{{ $ongoingCount }}</span>
        </a>
        <a href="{{ route($projectsIndex, ['filter' => 'overdue']) }}" class="tl-kpi k-overdue">
            <span class="tl-kpi-label">Overdue</span>
            <span class="tl-kpi-num">{{ $overdueCount }}</span>
        </a>
        <button type="button" class="tl-kpi k-ext" id="tlExtFilter" aria-pressed="false"
                title="Show only projects with an extension request">
            <span class="tl-kpi-label">Extensions granted</span>
            <span class="tl-kpi-num">{{ $extensionSummary['approved'] }}</span>
        </button>
    </div>

    <div class="tl-tip" id="tlTip" role="tooltip" hidden></div>
</div>

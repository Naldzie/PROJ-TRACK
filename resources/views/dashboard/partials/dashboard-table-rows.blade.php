{{-- ═══════════════════════════════════════════════════════════════════
     DASHBOARD ONGOING PROJECTS — TABLE ROWS PARTIAL
     Used by: dashboard.blade.php (default 10 rows via @include)
              DashboardController@filter() (AJAX filtered results)
     Variables received:
       $ongoingProjects       — collection of Project models to loop over
       $currentMonthProgress  — keyed by project_id (current month actual)
       $currentMonthBaseline  — keyed by project_id (current month planned)
       $latestKnownProgress   — keyed by project_id (last known actual, any month)
═══════════════════════════════════════════════════════════════════ --}}
{{-- ═══════════════════════════════════════════════════════
                        ONGOING PROJECTS LOOP
                        Data source: DashboardController@index
                        Variables:  $ongoingProjects       — collection of ongoing Project models
                                    $currentMonthProgress  — keyed by project_id, current month's
                                                            ProjectProgressHistory record (or null)
                    ═══════════════════════════════════════════════════════ --}}
                    @forelse($ongoingProjects as $project)

                        @php
                            // ── OVERALL PROGRESS ──────────────────────────────────────────────
                            // Read directly from pre-fetched $latestKnownProgress instead of calling
                            // getProgressPercentage() which hits the DB once per row (N+1).
                            $progress = isset($latestKnownProgress[$project->id])
                                ? (float) ($latestKnownProgress[$project->id]->actual_progress_percent ?? 0)
                                : 0;

                            // ── THIS MONTH: PLANNED vs ACTUAL ─────────────────────────────
                            // Actual: current month record if available, otherwise last known actual.
                            // Planned: from ProjectBaselineSchedule for current month.
                            $monthRecord     = $currentMonthProgress[$project->id] ?? null;
                            $monthBaseline   = $currentMonthBaseline[$project->id] ?? null;
                            $latestRecord    = $latestKnownProgress[$project->id] ?? null;

                            // Use current month actual if available, otherwise fall back to last known actual
                            $monthActual     = $monthRecord  ? (float) $monthRecord->actual_progress_percent
                                            : ($latestRecord ? (float) $latestRecord->actual_progress_percent
                                            : null);

                            $monthPlanned    = $monthBaseline ? (float) $monthBaseline->planned_progress_percent : null;

                            $hasActual       = $monthActual  !== null;
                            $hasPlanned      = $monthBaseline !== null;
                            $isCurrentMonth  = $monthRecord  !== null; // true only if inspected THIS month

                            // ── HEALTH (single source of truth) ─────────────────────────
                            // ProjectHealthService::determine() classifies from the pre-fetched
                            // maps below — ZERO DB queries per row (was 3+ via isOverdue()/
                            // isAtRisk()/getRiskColor(), each hitting the DB through
                            // getProgressPercentage()). Same definition as the AI panel.
                            $health = app(\App\Services\ProjectHealthService::class)->determine($project, [
                                'latest_progress'        => $latestRecord,
                                'current_month_history'  => $monthRecord,
                                'current_month_baseline' => $monthBaseline,
                                'baseline_schedules'     => $baselineSchedules[$project->id] ?? collect(),
                                'open_high_issues'       => (int) ($openHighIssueCounts[$project->id] ?? 0) > 0,
                                'aging_issue_count'      => (int) ($agingIssueCounts[$project->id] ?? 0),
                                'open_high_risks'        => (int) ($openHighRiskCounts[$project->id] ?? 0) > 0,
                                'pending_extension'      => (int) ($pendingExtensionCounts[$project->id] ?? 0) > 0,
                            ]);

                            $fillClass     = $health['fill_class'];
                            $riskLabel     = $health['label'];
                            $riskClass     = $health['risk_class'];
                            $dotColor      = $health['dot_color'];
                            $healthTitle   = $health['tooltip'];
                            $isOverdue     = $health['is_overdue'];

                            // ── DEADLINE DISPLAY ──────────────────────────────────────────
                            $days          = $health['days_remaining']; // null if no planned_end_date
                            $deadlineClass = $health['deadline_class'];

                            // ── ROW LINK ──────────────────────────────────────────────────
                            // Points to the correct detail route based on the logged-in user's role.
                            $detailRoute = auth()->user()->role === 'staff'
                                ? route('staff.projects.detail', $project->id)
                                : route('admin.projects.detail',  $project->id);
                        @endphp

                        {{-- Data attributes drive the JS filter — computed from same @php block above --}}
                        <tr data-overdue="{{ $isOverdue ? '1' : '0' }}"
                            data-atrisk="{{ $health['is_at_risk'] ? '1' : '0' }}"
                            data-pending="{{ !$hasActual ? '1' : '0' }}">
                            {{-- Project identity: code, title, category --}}
                            <td>
                                <div class="project-code">{{ $project->code }}</div>
                                <div class="project-title">{{ $project->title }}</div>
                                <span class="project-category">{{ $project->category->name ?? '—' }}</span>
                            </td>

                            {{-- Assigned contractor company name --}}
                            <td>
                                <div class="contractor-name">
                                    {{ $project->contractor->company_name ?? '—' }}
                                </div>
                            </td>

                            {{-- This month's progress: planned vs actual
                                Falls back to overall progress if no record exists for this month --}}
                            <td>
                                @if($hasActual || $hasPlanned)
                                    <div class="this-month-label">

                                        {{-- Planned target for this month --}}
                                        @if($hasPlanned)
                                            Planned <strong style="color:var(--gray-700)">{{ number_format($monthPlanned, 0) }}%</strong>
                                        @else
                                            <span style="color:var(--gray-400)">No target set</span>
                                        @endif

                                        ·

                                        {{-- Actual: current month or last known --}}
                                        @if($hasActual)
                                            @if($isCurrentMonth)
                                                Actual
                                            @else
                                                {{-- Not inspected this month yet — show last known with label --}}
                                                Last
                                            @endif
                                            @if($monthActual >= ($monthPlanned ?? 0))
                                                <strong style="color:var(--green-600)">{{ number_format($monthActual, 0) }}%</strong>
                                            @else
                                                <strong style="color:var(--red-600)">{{ number_format($monthActual, 0) }}%</strong>
                                            @endif
                                        @else
                                            <span style="color:var(--gray-400);font-style:italic">no record</span>
                                        @endif

                                    </div>
                                    <div class="progress-visual">
                                        <div class="progress-bar-mini">
                                            <div class="fill {{ $fillClass }}"
                                                style="width:{{ $hasActual ? $monthActual : $monthPlanned }}%;
                                                        {{ !$isCurrentMonth ? 'opacity:0.6' : '' }}">
                                            </div>
                                        </div>
                                        <span class="progress-percent" style="{{ !$isCurrentMonth ? 'color:var(--gray-400)' : '' }}">
                                            {{ $hasActual ? number_format($monthActual, 0) : number_format($monthPlanned, 0) }}%
                                        </span>
                                    </div>
                                    @if(!$isCurrentMonth && $hasActual)
                                        <div style="font-size:10px;color:var(--gray-400);margin-top:2px;font-style:italic">
                                            No {{ now()->format('M') }} inspection yet
                                        </div>
                                    @endif

                                @else
                                    {{-- No baseline and no actual record at all — show overall cumulative progress --}}
                                    <div class="this-month-label">
                                        Overall <strong style="color:var(--gray-700)">{{ number_format($progress, 0) }}%</strong>
                                    </div>
                                    <div class="progress-visual">
                                        <div class="progress-bar-mini">
                                            <div class="fill {{ $fillClass }}" style="width:{{ $progress }}%"></div>
                                        </div>
                                        <span class="progress-percent">{{ number_format($progress, 0) }}%</span>
                                    </div>
                                    <div style="font-size:10px;color:var(--gray-400);margin-top:2px">
                                        No {{ now()->format('M') }} baseline
                                    </div>
                                @endif
                            </td>

                            {{-- Deadline: formatted date + days remaining or overdue count --}}
                            <td>
                                <div class="deadline-cell {{ $deadlineClass }}">
                                    {{ $project->planned_end_date?->format('M d, Y') ?? '—' }}
                                </div>
                                @if($isOverdue)
                                    <div class="days-label overdue-text">
                                        {{ abs($days) }}d overdue
                                    </div>
                                @elseif($days !== null)
                                    <div class="days-label {{ $days < 30 ? 'days-urgent' : 'days-ok' }}">
                                        {{ $days }}d left
                                    </div>
                                @endif
                            </td>

                            {{-- Health badge: ON TRACK / AT RISK / OVERDUE / NO DATA --}}
                            <td>
                                <span class="risk-indicator {{ $riskClass }}" title="{{ $healthTitle }}">
                                    <span class="risk-dot" style="background:{{ $dotColor }}"></span>
                                    {{ $riskLabel }}
                                </span>
                                @if($health['escalated'] ?? false)
                                <span style="margin-left: 4px; color: #d97706; cursor: help;" title="{{ $health['escalation_reason'] }}">
                                    <i class="bi bi-info-circle" style="font-size: 12px;"></i>
                                </span>
                                @endif
                            </td>

                            {{-- Row action: links to the project detail page --}}
                            <td>
                                <a href="{{ $detailRoute }}" class="db-row-link">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </td>
                        </tr>

                    @empty
                        {{-- Shown when there are no ongoing projects --}}
                        <tr>
                            <td colspan="6" class="db-empty">
                                <i class="bi bi-check-circle"></i>
                                <div class="db-empty-title">No ongoing projects at the moment</div>
                                <div>Projects that are being worked on right now will appear here.</div>
                            </td>
                        </tr>
                    @endforelse
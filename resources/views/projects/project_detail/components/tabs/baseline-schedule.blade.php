<!-- safe to consolidate to this file -->
@php
    // Cache-busting for feature scripts (same $jsv convention as _create_project_modal):
    // filemtime query strings so browser caches invalidate on every deploy/edit.
    $jsv = fn($p) => asset($p) . '?v=' . filemtime(public_path($p));
@endphp

<!-- Set window variables for utility functions before loading -->
<script>
    window.projectId = '{{ $project->id }}';
    window.routePrefix = '{{ $routePrefix }}';
    window.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    {{-- NEW: lets the JS validation mirror the controller's extension-aware 100%-cap logic --}}
    window.projectExtensionCount = {{ $project->extension_count ?? 0 }};
    {{-- Expose progress records for edit modal --}}
    window._progressRecordData = @json($project->progressHistory->load('recordedBy')->load('attachments')->each->setAppends(['recorded_by_name', 'activity_names'])->keyBy('id'));
</script>

<!-- Load Baseline Schedule Utilities -->
<script src="{{ asset('js/admin/projects/baseline-schedule-utils.js') }}"></script>

<!-- Load shared S-Curve Chart functions (used by Progress tab and Set Up Baseline modal) -->
<script src="{{ asset('js/admin/projects/s-curve-chart.js') }}"></script>

<!-- Restore Active Tab on Page Load -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        consumePendingToast();
        const activeTab = sessionStorage.getItem('activeTab');
        if (activeTab) {
            console.log('[DOMContentLoaded] Restoring tab:', activeTab);
            sessionStorage.removeItem('activeTab');
            const tabButton = document.querySelector(`[data-tab="${activeTab}"]`);
            if (tabButton) {
                console.log('[DOMContentLoaded] Activating tab button:', activeTab);
                switchMainTab(activeTab, { preventDefault: () => {} });
            }
        }
    });
</script>

<!-- PROJECT PROGRESS BASELINE TAB (Replaces Milestones) -->
<div id="s-curve-planning" class="tab-content"
     data-project-start-date="{{ $project->planned_start_date?->toDateString() ?? $project->actual_start_date?->toDateString() ?? '' }}"
     data-project-end-date="{{ $project->planned_end_date?->toDateString() ?? '' }}">

<!-- ==================== PROJECT PROGRESS BASELINE SETUP SECTION ==================== -->
<div class="s-curve-planning-section">
    <div class="section-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
            <i class="bi bi-graph-up" style="font-size: 20px;"></i> Monthly Progress Targets
        </h3>
        <div style="display: flex; gap: 8px;">
            @php
                $hasBaseline = $project->baselineSchedules()->exists();
                $scheduleDoc = $project->documents()->byTypeName('Schedule')->latest()->first();
            @endphp
            @can('update', $project)
                @if(!$hasBaseline)
                    <button class="btn-primary" onclick="openSetupBaselineModal()" style="padding: 8px 16px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.2s;">
                        <i class="bi bi-flag"></i> Set Up Baseline
                    </button>
                    @php
                        $scheduleExt = strtolower($scheduleDoc->file_extension ?? '');
                    @endphp
                    @if($scheduleDoc && in_array($scheduleExt, ['xls', 'xlsx', 'pdf']))
                        <button id="scurveImportBtnDetail" class="btn-primary"
                                data-parse-url="{{ route($routePrefix . '.s-curve.parse') }}"
                                data-document-id="{{ $scheduleDoc->id }}"
                                data-doc-type="{{ $scheduleExt }}"
                                data-download-url="{{ $scheduleExt === 'pdf' ? route(auth()->user()->role . '.documents.download', $scheduleDoc->id) : '' }}"
                                data-document-name="{{ $scheduleDoc->document_name }}"
                                data-start-date="{{ $project->planned_start_date?->toDateString() ?? '' }}"
                                data-end-date="{{ $project->planned_end_date?->toDateString() ?? '' }}"
                                style="padding: 8px 16px; background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.2s;">
                            <i class="bi bi-cloud-arrow-up-fill"></i> Import from S-curve
                        </button>
                    @endif
                @else
                    @if(auth()->user()->role === 'admin' || $isAssignedStaff)
                        <button class="btn-primary" onclick="openEditOriginalBaselineModal()" style="padding: 8px 16px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.2s;">
                            <i class="bi bi-pencil-square"></i> Edit Original Baseline
                        </button>
                    @endif
                    @if($project->extension_count > 0)
                        <button class="btn-primary" onclick="openAddMonthTargetModal()" style="padding: 8px 16px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.2s;">
                            <i class="bi bi-plus-circle"></i> Add Month Target
                        </button>
                        <button class="btn-primary" onclick="openAdjustBaselineModal()" style="padding: 8px 16px; background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.25); border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.2s;">
                            <i class="bi bi-arrow-repeat"></i> Generate Adjusted Baseline
                        </button>
                    @endif
                @endif
            @endcan
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="summary" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; padding: 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
        <div style="padding: 15px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
            <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 6px;">Total Months</div>
            <div style="font-size: 24px; font-weight: 700; color: #667eea;">{{ $project->baselineSchedules()->count() ?? 0 }}</div>
        </div>
        <div style="padding: 15px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
            <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 6px;">Current Month Target</div>
            <div style="font-size: 24px; font-weight: 700; color: #10b981;">
                @php
                    if ($project->status === 'Completed') {
                        $latestTargetPercent = '✓ Done';
                    } else {
                        $today = now();
                        $currentYear = $today->year;
                        $currentMonth = $today->month;
                        $currentMonthLabel = sprintf('%04d-%02d', $currentYear, $currentMonth);
                        $currentMonthTarget = $project->baselineSchedules()
                            ->where('month_label', $currentMonthLabel)
                            ->first();
                        if (!$currentMonthTarget) {
                            $currentMonthTarget = $project->baselineSchedules()
                                ->where('planned_date', '>', $today)
                                ->orderBy('planned_date', 'asc')
                                ->first();
                        }
                        if (!$currentMonthTarget) {
                            $currentMonthTarget = $project->baselineSchedules()
                                ->where('planned_date', '<=', $today)
                                ->orderBy('planned_date', 'desc')
                                ->first();
                        }
                        $latestTargetPercent = $currentMonthTarget?->planned_progress_percent ?? 0;
                    }
                @endphp
                {{ $latestTargetPercent }}{{ is_numeric($latestTargetPercent) ? '%' : '' }}
            </div>
        </div>
        <div style="padding: 15px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
            <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 6px;">Total Activities</div>
            <div style="font-size: 24px; font-weight: 700; color: #3b82f6;">{{ $project->activities()->count() ?? 0 }}</div>
        </div>
        <div style="padding: 15px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
            <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 6px;">Completed</div>
            <div style="font-size: 24px; font-weight: 700; color: #16a34a;">{{ $project->activities()->where('is_completed', true)->count() ?? 0 }}</div>
        </div>
    </div>

    {{-- ================================================================== --}}
    {{-- HORIZONTAL TIMELINE                                                 --}}
    {{-- ================================================================== --}}
    @php
        $highestActualProgress = $project->progressHistory()->max('actual_progress_percent') ?? 0;
        $progressMonths = $project->progressHistory()
            ->whereNotNull('month_label')
            ->pluck('month_label')->unique()->values()->all();
        $latestProgressMonth = !empty($progressMonths) ? max($progressMonths) : null;
        $nextPendingMonth = null;
        foreach ($project->baselineSchedules()->orderBy('planned_date', 'asc')->pluck('month_label') as $bm) {
            if (!in_array($bm, $progressMonths, true)) { $nextPendingMonth = $bm; break; }
        }
        $baselineMonths = $project->baselineSchedules()->orderBy('planned_date', 'asc')->get();
        $totalDots = $baselineMonths->count();
        $currentMonthLabel = $currentMonthLabel ?? now()->format('Y-m');
        $useShortLabels = $totalDots >= 18;
    @endphp

    @if($totalDots > 0)
    <div style="padding: 20px 0;">
        {{-- Timeline Track --}}
        <div class="timeline-wrapper">
            <div class="timeline-container" id="sCurvePlanTableBody">
                <div class="timeline-track">
                    @php
                        $currentIdx = 0;
                        foreach ($baselineMonths as $idx => $bl) {
                            if ($bl->month_label <= $currentMonthLabel) $currentIdx = $idx;
                        }
                        $fillPct = $totalDots > 1 ? ($currentIdx / ($totalDots - 1)) * 100 : 0;
                    @endphp
                    <div class="timeline-track-fill" style="width: {{ $fillPct }}%;"></div>
                </div>

                @foreach($baselineMonths as $plan)
                    @php
                        $activitiesCount = $project->activities()->where('month_label', $plan->month_label)->count();
                        $activitiesCompleted = $project->activities()->where('month_label', $plan->month_label)->where('is_completed', true)->count();
                        $latestProgress = $plan->progressHistory()->with('attachments')
                            ->orderBy('month_label','desc')->orderBy('inspection_date','desc')->orderBy('id','desc')->first();
                        $collapseId = 'activities-' . $plan->id;
                        $carriedInCount = $project->activities()->where('month_label', $plan->month_label)->whereHas('carryovers')->count();
                        $incompleteCount = $activitiesCount - $activitiesCompleted;
                        $hasFutureMonths = $project->baselineSchedules()->where('planned_date', '>', $plan->planned_date)->exists();
                        $hasActual = $latestProgress && $latestProgress->actual_progress_percent !== null;
                        $isCurrentMonth = $plan->month_label === $currentMonthLabel;
                        $isPastMonth = $plan->month_label < $currentMonthLabel;
                        $showRecordButton = (!$hasActual && $plan->month_label <= $currentMonthLabel) || $plan->month_label === $latestProgressMonth;
                        $prevTarget = $project->baselineSchedules()->where('planned_date', '<', $plan->planned_date)->max('planned_progress_percent');
                        $nextTarget = $project->baselineSchedules()->where('planned_date', '>', $plan->planned_date)->min('planned_progress_percent');
                        if ($hasActual) {
                            $dotState = 'completed';
                        } elseif ($isPastMonth) {
                            $dotState = 'missing';
                        } elseif ($isCurrentMonth) {
                            $dotState = 'current';
                        } else {
                            $dotState = 'future';
                        }
                    @endphp

                    {{-- DOT --}}
                    <div class="timeline-dot {{ $dotState }}" data-month-label="{{ $plan->month_label }}" data-plan-id="{{ $plan->id }}" data-planned-progress="{{ $plan->planned_progress_percent }}" data-actual-progress="{{ $latestProgress ? $latestProgress->actual_progress_percent : '' }}"
                         onclick="toggleTimelineDetail('{{ $collapseId }}', this)">
                        {{-- Always-visible info for current month (above label) --}}
                        @if($isCurrentMonth)
                        <div class="timeline-always-info">
                            <div class="always-month">{{ $plan->planned_date->format('F - Y') }}</div>
                            <div class="always-date">{{ $plan->planned_date->format('F d, Y') }}</div>
                            <div class="always-progress">
                                <span class="always-planned">Plan: {{ $plan->planned_progress_percent }}%</span>
                                <span class="always-actual">Actual: {{ $latestProgress ? $latestProgress->actual_progress_percent . '%' : '—' }}</span>
                            </div>
                        </div>
                        @endif
                        <span class="timeline-dot-label">{{ $useShortLabels ? $plan->planned_date->format('M - Y') : $plan->planned_date->format('F - Y') }}</span>
                        <div class="timeline-dot-circle">
                            @if($dotState === 'completed')
                                <i class="bi bi-check-lg"></i>
                            @elseif($dotState === 'missing')
                                <i class="bi bi-exclamation-lg"></i>
                            @endif
                        </div>

                        {{-- Hover Tooltip --}}
                        <div class="timeline-tooltip">
                            <div class="tooltip-date">{{ $plan->planned_date->format('F d, Y') }}</div>
                            <div class="tooltip-section">
                                <span class="tooltip-label">Planned</span>
                                <span class="tooltip-value" style="color:#667eea;">{{ $plan->planned_progress_percent }}%</span>
                            </div>
                            <div class="tooltip-section">
                                <span class="tooltip-label">Actual</span>
                                @if($latestProgress)
                                    <span class="tooltip-value" style="color:#10b981;">{{ $latestProgress->actual_progress_percent }}%</span>
                                @else
                                    <span class="tooltip-value" style="color:#9ca3af;">—</span>
                                @endif
                            </div>
                            @if($latestProgress)
                            <div class="tooltip-inspection">
                                <i class="bi bi-calendar3" style="font-size:10px;"></i>
                                {{ \Carbon\Carbon::parse($latestProgress->inspection_date)->format('M d, Y') }}
                            </div>
                            @endif
                            @if($dotState === 'missing')
                                <div style="color: #f97316; font-size: 10px; margin-top: 4px;">No progress recorded</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Detail Panel --}}
        <div id="timeline-detail-panel"></div>
    </div>

    {{-- Pre-rendered detail panels (hidden, moved into #timeline-detail-panel on click) --}}
    @foreach($baselineMonths as $plan)
        @php
            $activitiesCount = $project->activities()->where('month_label', $plan->month_label)->count();
            $activitiesCompleted = $project->activities()->where('month_label', $plan->month_label)->where('is_completed', true)->count();
            $latestProgress = $plan->progressHistory()->with('attachments')
                ->orderBy('month_label','desc')->orderBy('inspection_date','desc')->orderBy('id','desc')->first();
            $collapseId = 'activities-' . $plan->id;
            $carriedInCount = $project->activities()->where('month_label', $plan->month_label)->whereHas('carryovers')->count();
            $incompleteCount = $activitiesCount - $activitiesCompleted;
            $hasFutureMonths = $project->baselineSchedules()->where('planned_date', '>', $plan->planned_date)->exists();
            $hasActual = $latestProgress && $latestProgress->actual_progress_percent !== null;
            $showRecordButton = (!$hasActual && $plan->month_label <= $currentMonthLabel) || $plan->month_label === $latestProgressMonth;
            $prevTarget = $project->baselineSchedules()->where('planned_date', '<', $plan->planned_date)->max('planned_progress_percent');
            $nextTarget = $project->baselineSchedules()->where('planned_date', '>', $plan->planned_date)->min('planned_progress_percent');
            $monthActivities = $project->activities()->where('month_label', $plan->month_label)->with('carryovers')->get();
        @endphp
        <div id="{{ $collapseId }}" class="timeline-detail-panel" style="display:none;">
            <div class="timeline-detail-header">
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <span data-month-label="{{ $plan->month_label }}" style="background:#dbeafe; color:#0369a1; padding:6px 12px; border-radius:6px; font-size:13px; font-weight:700;">{{ $plan->planned_date->format('F - Y') }}</span>
                    <span style="color:#6b7280; font-size:13px;">{{ $plan->planned_date->format('F d, Y') }}</span>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:11px; color:#6b7280; text-transform:uppercase; font-weight:600;">Planned:</span>
                        <span style="font-weight:700; font-size:15px; color:#667eea; background:rgba(102,126,234,0.1); padding:4px 10px; border-radius:6px;">{{ $plan->planned_progress_percent }}%</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:11px; color:#6b7280; text-transform:uppercase; font-weight:600;">Actual:</span>
                        @if($latestProgress)
                            <span style="font-weight:700; font-size:15px; color:#10b981; background:rgba(16,185,129,0.1); padding:4px 10px; border-radius:6px;">{{ $latestProgress->actual_progress_percent }}% <span style="font-size:11px; color:#6b7280; font-weight:400;">{{ \Carbon\Carbon::parse($latestProgress->inspection_date)->format('M d, Y') }}</span></span>
                        @else
                            <span style="font-weight:700; font-size:15px; color:#6b7280; background:rgba(107,114,128,0.1); padding:4px 10px; border-radius:6px;">—</span>
                        @endif
                    </div>
                    @if($carriedInCount > 0)
                        <span style="background:#fef3c7; color:#92400e; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:600;"><i class="bi bi-arrow-return-right"></i> +{{ $carriedInCount }} carried over</span>
                    @endif
                    <div style="margin-left:auto; text-align:right;">
                        <span style="font-size:11px; color:#6b7280; text-transform:uppercase; font-weight:600;">Activities </span>
                        <span style="background:#f0f9ff; color:#0369a1; padding:4px 10px; border-radius:6px; font-size:13px; font-weight:600;">{{ $activitiesCompleted }}/{{ $activitiesCount }}</span>
                    </div>
                </div>
            </div>
            @if($latestProgress && $latestProgress->attachments->count() > 0)
            <div style="padding:10px 20px; background:white; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span style="font-size:11px; color:#6b7280; text-transform:uppercase; font-weight:600;">Evidence:</span>
                @foreach($latestProgress->attachments as $att)
                    <span class="attachment-chip me-1" title="{{ $att->original_name }}"
                          onclick="openDocumentViewer({id:{{ $att->id }}, name:'{{ addslashes($att->original_name) }}', type:'Inspection Evidence', size:'{{ $att->display_size }}', uploadedBy:'{{ addslashes($att->uploaded_by ?? '') }}', uploadedAt:'{{ $att->created_at ? $att->created_at->format('M d, Y H:i') : '' }}', viewUrl:'/{{ auth()->user()->role }}/attachments/{{ $att->id }}/view', downloadUrl:'/{{ auth()->user()->role }}/attachments/{{ $att->id }}/download', previewUrl:'/{{ auth()->user()->role }}/attachments/{{ $att->id }}/preview'})">
                        <i class="bi {{ $att->file_extension === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image' }}"></i>
                        <span>{{ $att->original_name }}</span>
                    </span>
                @endforeach
            </div>
            @endif
            <div class="timeline-detail-body">
                <div class="activities-grid">
                    @if($monthActivities->count() > 0)
                        @foreach($monthActivities as $activity)
                            @php
                                $originalMonth = null;
                                if ($activity->carryovers->isNotEmpty()) {
                                    $originalMonth = $activity->carryovers->first()->from_month_label;
                                }
                            @endphp
                            <div style="display:flex; align-items:flex-start; gap:12px; padding:12px; background:white; border-radius:8px; border:1px solid #e5e7eb;">
                                <input type="checkbox" id="activity-{{ $activity->id }}" data-activity-id="{{ $activity->id }}" class="activity-checkbox"
                                       onchange="toggleActivityCompletion({{ $activity->id }}, this.checked)"
                                       {{ $activity->is_completed ? 'checked' : '' }}
                                       style="width:20px; height:20px; cursor:pointer; flex-shrink:0; margin-top:2px;">
                                <div style="flex:1;">
                                    <label for="activity-{{ $activity->id }}" style="cursor:pointer; display:block;">
                                        <div style="font-weight:600; color:#111827; font-size:13px;">
                                            {{ $activity->activity_name }}
                                            @if($originalMonth)
                                                <span style="font-weight:400; font-size:11px; color:#f59e0b; margin-left:8px;"><i class="bi bi-arrow-return-right"></i> from {{ $originalMonth }}</span>
                                            @endif
                                        </div>
                                        @if($activity->description)
                                            <div style="color:#6b7280; font-size:12px; margin-top:4px;">{{ $activity->description }}</div>
                                        @endif
                                    </label>
                                </div>
                                <span style="background:{{ $activity->is_completed ? '#dcfce7' : '#fef3c7' }}; color:{{ $activity->is_completed ? '#166534' : '#92400e' }}; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:600; white-space:nowrap; flex-shrink:0;">
                                    {{ $activity->is_completed ? '✓ Done' : 'Pending' }}
                                </span>
                            </div>
                        @endforeach
                    @else
                        <div style="padding:20px; text-align:center; color:#9ca3af; font-size:12px;">
                            <i class="bi bi-inbox" style="font-size:24px; display:block; margin-bottom:8px; opacity:0.5;"></i>
                            No activities yet. Click "Manage Month" to add activities.
                        </div>
                    @endif
                </div>

                <div class="timeline-actions-row">
                    @if($showRecordButton && (auth()->user()->role === 'admin' || $isAssignedStaff))
                    <button onclick="openRecordProgressModal('{{ $plan->month_label }}', {{ $plan->planned_progress_percent }}, '{{ $plan->planned_date->toDateString() }}', {{ $latestProgress?->actual_progress_percent ?? 0 }}, {{ $highestActualProgress }}, {{ $loop->last ? '1' : '0' }})"
                            style="flex:1; background:#dcfce7; color:#166534; border:1px solid #86efac; padding:10px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:all 0.2s;"
                            title="Record Monthly Progress">
                        <i class="bi bi-graph-up-arrow"></i> Record Progress
                    </button>
                    @endif
                    @if($latestProgress && (auth()->user()->role === 'admin' || $isAssignedStaff) && ($plan->month_label === $latestProgressMonth))
                    <button onclick="openEditProgressModal({{ $latestProgress->id }}, '{{ $latestProgress->month_label }}', {{ $loop->last ? '1' : '0' }})"
                            style="flex:1; background:#dbeafe; color:#0369a1; border:1px solid #bae6fd; padding:10px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:all 0.2s;"
                            title="Edit Latest Progress Record">
                        <i class="bi bi-pencil"></i> Edit Progress
                    </button>
                    @endif
                    @if($latestProgress && $incompleteCount > 0 && $hasFutureMonths && (auth()->user()->role === 'admin' || $isAssignedStaff))
                    <button onclick="openCarryOverForMonth('{{ $plan->month_label }}')"
                            style="flex:1; background:#fef3c7; color:#92400e; border:1px solid #fde68a; padding:10px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:all 0.2s;"
                            title="Carry Over Incomplete Activities">
                        <i class="bi bi-arrow-right-circle"></i> ↗ Carry Over
                    </button>
                    @endif
                    @if(!$latestProgress && (auth()->user()->role === 'admin' || $isAssignedStaff))
                    @php
                        $manageMode = ($project->extension_count > 0 && !$latestProgress) ? 'edit' : 'activities-only';
                    @endphp
                    <button onclick="openManageMonthModal({{ $plan->id }}, '{{ $plan->month_label }}', '{{ $plan->planned_date->toDateString() }}', {{ $plan->planned_progress_percent }}, '{{ addslashes($plan->description ?? '') }}', '{{ $manageMode }}', {{ $prevTarget ?? 'null' }}, {{ $nextTarget ?? 'null' }})"
                            style="flex:1; background:#f0f0f0; color:#374151; border:1px solid #d1d5db; padding:10px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:all 0.2s;"
                            title="Manage Month Target and Activities">
                        <i class="bi bi-pencil-square"></i> Manage Month
                    </button>
                    @endif
                    @if($plan->original_planned_progress_percent === null && !$latestProgress && ($isAssignedStaff || auth()->user()->role === 'admin'))
                    <button onclick="deleteMonthTarget({{ $plan->id }})"
                            style="flex:1; background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; padding:10px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:all 0.2s;"
                            title="Delete Month Target">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                    @endif
                </div>
            </div>
        </div>
    @endforeach

    @else
    {{-- Empty State --}}
    <div style="padding:40px; text-align:center; color:#9ca3af; border:2px dashed #d1d5db; border-radius:10px; margin:20px 0;">
        <i class="bi bi-inbox" style="font-size:40px; display:block; margin-bottom:12px; opacity:0.5;"></i>
        <div style="font-size:14px; font-weight:500; margin-bottom:6px;">No baseline schedule yet</div>
        <div style="font-size:12px; color:#d1d5db;">Click "Add Month Target" to set up your project's monthly progress targets</div>
    </div>
    @endif

</div>

<!-- Include Project Extensions Section (only if baseline exists) -->
@if($hasBaseline)
@include('projects.project_detail.components.tabs.partials.extensions-section')
<script src="{{ asset('js/admin/projects/baseline-schedule-extensions.js') }}"></script>
@endif

<script>
function loadMonthActivities(monthLabel) {
    if (!monthLabel) {
        document.getElementById('activitiesTable').style.display = 'none';
        document.getElementById('activitiesEmpty').style.display = 'block';
        return;
    }
    fetch(`/api/project-activities?project_id={{ $project->id }}&month_label=${monthLabel}`)
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('activitiesTableBody');
            tbody.innerHTML = '';
            if (data.activities.length === 0) {
                document.getElementById('activitiesTable').style.display = 'none';
                document.getElementById('activitiesEmpty').style.display = 'block';
                return;
            }
            document.getElementById('activitiesTable').style.display = 'table';
            document.getElementById('activitiesEmpty').style.display = 'none';
            data.activities.forEach(activity => {
                const statusBg = activity.is_completed ? 'rgba(16, 185, 129, 0.1)' : 'rgba(107, 114, 128, 0.1)';
                const statusColor = activity.is_completed ? '#10b981' : '#6b7280';
                const statusText = activity.is_completed ? '✓ Completed' : 'Pending';
                const row = `
                    <tr style="border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 12px 15px; font-weight: 600; color: #111827;">${activity.activity_name}</td>
                        <td style="padding: 12px 15px; color: #6b7280; font-size: 13px;">${activity.description || '—'}</td>
                        <td style="padding: 12px 15px; text-align: center;">
                            <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; background: ${statusBg}; color: ${statusColor};">${statusText}</span>
                        </td>
                        <td style="padding: 12px 15px; text-align: center; display: flex; justify-content: center; gap: 6px;">
                            <button onclick="toggleActivityCompletion(${activity.id}, ${activity.is_completed ? 0 : 1})"
                                    style="background: ${activity.is_completed ? '#fee2e2' : '#dcfce7'}; color: ${activity.is_completed ? '#dc2626' : '#166534'}; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="bi ${activity.is_completed ? 'bi-x-circle' : 'bi-check-circle'}"></i>
                                ${activity.is_completed ? 'Mark Pending' : 'Mark Done'}
                            </button>
                        </td>
                    </tr>`;
                tbody.innerHTML += row;
            });
        })
        .catch(error => console.error('Error loading activities:', error));
}

function toggleActivityCompletion(activityId, isCompleted) {
    fetch(`/api/project-activities/${activityId}/toggle`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ is_completed: isCompleted })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const monthLabel = document.getElementById('currentMonthLabel')?.value;
            if (monthLabel) reloadInlineActivities(monthLabel);
        }
    })
    .catch(error => console.error('Error toggling activity:', error));
}
</script>

</div>


@include('components.delete-confirmation-modal')
@include('components.warning-confirm-modal')

<!-- MODALS FOR PROJECT PROGRESS TRACKING -->
@include('projects.project_detail.components.modals.add_month_target_modal')
@include('projects.project_detail.components.modals._setup_baseline_modal')
@include('projects.project_detail.components.modals._manage_month_modal')
@include('projects.project_detail.components.modals.record_progress_modal')
@include('projects.project_detail.components.modals._carry_over_activity_modal')
@include('projects.project_detail.components.modals._edit_progress_modal')

<script src="{{ asset('js/admin/projects/baseline-schedule-add-month-modal.js') }}"></script>
<script src="{{ $jsv('js/admin/projects/baseline-schedule-setup-modal.js') }}"></script>
<script type="module" src="{{ $jsv('js/admin/projects/scurve-import-core.js') }}"></script>
<script src="{{ $jsv('js/admin/projects/scurve-import-ui.js') }}"></script>
<script type="module" src="{{ $jsv('js/admin/projects/scurve-pdf-parser-core.js') }}"></script>
<script src="{{ $jsv('js/admin/projects/scurve-pdf-parser.js') }}"></script>
<script src="{{ asset('js/admin/projects/baseline-schedule-manage-month-modal.js') }}"></script>
<script src="{{ asset('js/admin/projects/progress-attachments.js') }}"></script>
<script src="{{ asset('js/admin/projects/baseline-schedule-record-progress-modal.js') }}"></script>
<script src="{{ asset('js/admin/projects/baseline-schedule-carry-over.js') }}"></script>
<script src="{{ asset('js/admin/projects/baseline-schedule-edit-progress-modal.js') }}"></script>

<script>
function reloadMonthCardFromServer(monthLabel) {
    return new Promise((resolve) => {
        console.log('[reloadMonthCardFromServer] Will reload page after 1.5 seconds');
        sessionStorage.setItem('activeTab', 's-curve-planning');
        setTimeout(() => { location.reload(); resolve(); }, 1500);
    });
}

let pendingDeletePlanId = null;

function deleteMonthTarget(planId) {
    pendingDeletePlanId = planId;
    showDeleteConfirmation(
        'Delete Month Target',
        `<strong style="color: #ef4444;"><i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px;"></i>Warning:</strong> Are you sure you want to delete this month target and all its activities? This action cannot be undone.`,
        confirmDeleteMonthTarget
    );
}

function closeDeleteConfirmModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
    if (modal) modal.hide();
}

function confirmDeleteMonthTarget() {
    if (!pendingDeletePlanId) return;
    const planId = pendingDeletePlanId;
    pendingDeletePlanId = null;
    const token = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) {
        showToast('Security error: CSRF token not found. Please refresh the page.', 'error');
        closeDeleteConfirmModal();
        return;
    }

    // Dismiss confirmation modal and show full-screen preloader
    closeDeleteConfirmModal();
    if (window.ProjTrackPreloader && window.ProjTrackPreloader.show) {
        window.ProjTrackPreloader.show('Deleting month target&hellip;');
    }

    fetch(`${buildBaselineScheduleEndpoint()}/${planId}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            queueToastAfterReload('Month target deleted successfully!', 'success');
            sessionStorage.setItem('activeTab', 's-curve-planning');
            setTimeout(() => location.reload(), 600);
        } else {
            if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
            showToast('Error: ' + (data.message || 'Failed to delete'), 'error');
        }
    })
    .catch(error => {
        if (window.ProjTrackPreloader && window.ProjTrackPreloader.hide) window.ProjTrackPreloader.hide();
        console.error('Error:', error);
        showToast('Error deleting month target: ' + error.message, 'error');
    });
}
</script>

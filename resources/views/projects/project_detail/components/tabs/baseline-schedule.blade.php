<!-- safe to consolidate to this file -->

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
        // Show any toast queued by queueToastAfterReload() (e.g. a baseline
        // add/edit/delete) so it appears AFTER the reload instead of before.
        consumePendingToast();

        const activeTab = sessionStorage.getItem('activeTab');
        if (activeTab) {
            console.log('[DOMContentLoaded] Restoring tab:', activeTab);
            sessionStorage.removeItem('activeTab'); // Clear after use
            // Activate the tab using Bootstrap
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
                    {{-- No baseline yet: open the dedicated Set Up Baseline modal (B3.2). --}}
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
                    {{-- Admin or assigned staff: re-open the Set Up Baseline modal to correct the locked original plan (B3.2). --}}
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
                        
                        // Try to find current month's target using month_label (e.g., "2026-04")
                        $currentMonthTarget = $project->baselineSchedules()
                            ->where('month_label', $currentMonthLabel)
                            ->first();
                        
                        // If not found, get the nearest upcoming target
                        if (!$currentMonthTarget) {
                            $currentMonthTarget = $project->baselineSchedules()
                                ->where('planned_date', '>', $today)
                                ->orderBy('planned_date', 'asc')
                                ->first();
                        }
                        
                        // If still not found, get the most recent past target
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

    <!-- Monthly Baseline Schedule Cards - Single Column -->
    <div id="sCurvePlanTableBody" style="display: flex; flex-direction: column; gap: 16px; padding: 20px 0;">
        @php
            // Calculate highest overall actual progress across all months
            $highestActualProgress = $project->progressHistory()->max('actual_progress_percent') ?? 0;

            // Fix C: Record Progress is available on exactly TWO monthly
            // targets — the CURRENT/latest month with progress (so the user can
            // add another inspection report) and the NEXT PENDING month (the
            // first one with no progress yet). All other months must use Edit.
            $progressMonths = $project->progressHistory()
                ->whereNotNull('month_label')
                ->pluck('month_label')
                ->unique()
                ->values()
                ->all();
            $latestProgressMonth = !empty($progressMonths) ? max($progressMonths) : null;
            $nextPendingMonth = null;
            foreach ($project->baselineSchedules()->orderBy('planned_date', 'asc')->pluck('month_label') as $baselineMonth) {
                if (!in_array($baselineMonth, $progressMonths, true)) {
                    $nextPendingMonth = $baselineMonth;
                    break;
                }
            }
        @endphp
        @forelse($project->baselineSchedules()->orderBy('planned_date', 'asc')->get() as $plan)
            @php
                $activitiesCount = $project->activities()->where('month_label', $plan->month_label)->count();
                $activitiesCompleted = $project->activities()->where('month_label', $plan->month_label)->where('is_completed', true)->count();
                $latestProgress = $plan->progressHistory()
                    ->with('attachments')
                    ->orderBy('month_label', 'desc')
                    ->orderBy('inspection_date', 'desc')
                    ->orderBy('id', 'desc')
                    ->first();
                $collapseId = 'activities-' . $plan->id;
                $carriedInCount = $project->activities()
                    ->where('month_label', $plan->month_label)
                    ->whereHas('carryovers')
                    ->count();
                $incompleteCount = $activitiesCount - $activitiesCompleted;
                $hasFutureMonths = $project->baselineSchedules()
                    ->where('planned_date', '>', $plan->planned_date)
                    ->exists();
                // Record Progress shows on the current/latest month (extra
                // inspection) and on the next pending month (first with none).
                $showRecordButton = $plan->month_label === $latestProgressMonth
                    || $plan->month_label === $nextPendingMonth;
                // Neighboring targets drive the Manage Month ordering lock hint.
                $prevTarget = $project->baselineSchedules()
                    ->where('planned_date', '<', $plan->planned_date)
                    ->max('planned_progress_percent');
                $nextTarget = $project->baselineSchedules()
                    ->where('planned_date', '>', $plan->planned_date)
                    ->min('planned_progress_percent');
            @endphp
            <!-- Month Card -->
            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.3s;">
                <!-- Card Header - Month Info (Clickable) -->
                <div onclick="toggleActivityCollapse('{{ $collapseId }}')" 
                     style="padding: 18px 20px; background: linear-gradient(135deg, #f3f4f6 0%, #ffffff 100%); cursor: pointer; display: flex; align-items: center; justify-content: space-between; transition: background 0.2s; border-bottom: 1px solid #e5e7eb;"
                     onmouseover="this.style.background='linear-gradient(135deg, #efefef 0%, #f9f9f9 100%)'" 
                     onmouseout="this.style.background='linear-gradient(135deg, #f3f4f6 0%, #ffffff 100%)'">
                    
                    <!-- Left: Month and Date Info -->
                    <div style="flex: 1; display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <span data-month-label="{{ $plan->month_label }}" style="background: #dbeafe; color: #0369a1; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 700;">
                                {{ $plan->planned_date->format('F - Y') }}
                            </span>
                            <span style="color: #6b7280; font-size: 13px; font-weight: 500;">{{ $plan->planned_date->format('F d, Y') }}</span>
                        </div>
                        
                        <!-- Progress indicators -->
                        <div style="display: flex; align-items: center; gap: 20px; margin-top: 4px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 11px; color: #6b7280; text-transform: uppercase; font-weight: 600;">Planned:</span>
                                <div style="display: inline-flex; align-items: center; gap: 6px; background: rgba(102, 126, 234, 0.1); padding: 6px 12px; border-radius: 6px;">
                                    <span style="font-weight: 700; font-size: 15px; color: #667eea;">{{ $plan->planned_progress_percent }}%</span>
                                </div>
                            </div>
                            
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 11px; color: #6b7280; text-transform: uppercase; font-weight: 600;">Actual:</span>
                                @if($latestProgress)
                                    <div style="display: inline-flex; align-items: center; gap: 6px; background: rgba(16, 185, 129, 0.1); padding: 6px 12px; border-radius: 6px;">
                                        <span style="font-weight: 700; font-size: 15px; color: #10b981;">{{ $latestProgress->actual_progress_percent }}%</span>
                                        <span style="font-size: 11px; color: #6b7280;">{{ \Carbon\Carbon::parse($latestProgress->inspection_date)->format('M d, Y') }}</span>
                                    </div>
                                @else
                                    <div style="display: inline-flex; align-items: center; gap: 6px; background: rgba(107, 114, 128, 0.1); padding: 6px 12px; border-radius: 6px;">
                                        <span style="font-weight: 700; font-size: 15px; color: #6b7280;">—</span>
                                    </div>
                                @endif
                            </div>

                            @if($carriedInCount > 0)
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap;">
                                    <i class="bi bi-arrow-return-right" style="margin-right: 2px;"></i> +{{ $carriedInCount }} carried over
                                </span>
                            </div>
                            @endif
                        </div>

                        @if($latestProgress && $latestProgress->attachments->count() > 0)
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="font-size: 11px; color: #6b7280; text-transform: uppercase; font-weight: 600;">Evidence:</span>
                            @foreach($latestProgress->attachments as $att)
                                <span class="attachment-chip me-1" title="{{ $att->original_name }}"
                                      onclick="openDocumentViewer({id: {{ $att->id }}, name: '{{ addslashes($att->original_name) }}', type: 'Inspection Evidence', size: '{{ $att->display_size }}', uploadedBy: '{{ addslashes($att->uploaded_by ?? '') }}', uploadedAt: '{{ $att->created_at ? $att->created_at->format('M d, Y H:i') : '' }}', viewUrl: '/{{ auth()->user()->role }}/attachments/{{ $att->id }}/view', downloadUrl: '/{{ auth()->user()->role }}/attachments/{{ $att->id }}/download', previewUrl: '/{{ auth()->user()->role }}/attachments/{{ $att->id }}/preview'})">
                                    <i class="bi {{ $att->file_extension === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image' }}"></i>
                                    <span>{{ $att->original_name }}</span>
                                </span>
                            @endforeach
                        </div>
                        @endif
                    </div>

                    <!-- Right: Collapse Icon & Actions -->
                    <div style="display: flex; align-items: center; gap: 12px; margin-left: 16px;">
                        <div style="text-align: right;">
                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Activities</div>
                            <span style="background: #f0f9ff; color: #0369a1; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600;">
                                {{ $activitiesCompleted }}/{{ $activitiesCount }}
                            </span>
                        </div>
                        <i class="bi bi-chevron-down" id="chevron-{{ $collapseId }}" style="font-size: 18px; color: #667eea; transition: transform 0.3s; margin-top: 12px;"></i>
                    </div>
                </div>

                <!-- Activities Collapsible Section -->
                <div id="{{ $collapseId }}" style="display: none; padding: 16px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb;">
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <!-- Activities List -->
                        @php
                            $monthActivities = $project->activities()->where('month_label', $plan->month_label)->with('carryovers')->get();
                        @endphp
                        
                        @if($monthActivities->count() > 0)
                            @foreach($monthActivities as $activity)
                                @php
                                    $originalMonth = null;
                                    if ($activity->carryovers->isNotEmpty()) {
                                        $originalMonth = $activity->carryovers->first()->from_month_label;
                                    }
                                @endphp
                                <div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                                    <input type="checkbox" 
                                           id="activity-{{ $activity->id }}" 
                                           data-activity-id="{{ $activity->id }}" 
                                           class="activity-checkbox"
                                           onchange="toggleActivityCompletion({{ $activity->id }}, this.checked)"
                                           {{ $activity->is_completed ? 'checked' : '' }}
                                           style="width: 20px; height: 20px; cursor: pointer; flex-shrink: 0; margin-top: 2px;">
                                    <div style="flex: 1;">
                                        <label for="activity-{{ $activity->id }}" style="cursor: pointer; display: block;">
                                            <div style="font-weight: 600; color: #111827; font-size: 13px;">
                                                {{ $activity->activity_name }}
                                                @if($originalMonth)
                                                    <span style="font-weight: 400; font-size: 11px; color: #f59e0b; margin-left: 8px;">
                                                        <i class="bi bi-arrow-return-right"></i> from {{ $originalMonth }}
                                                    </span>
                                                @endif
                                            </div>
                                            @if($activity->description)
                                                <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">{{ $activity->description }}</div>
                                            @endif
                                        </label>
                                    </div>
                                    <span style="background: {{ $activity->is_completed ? '#dcfce7' : '#fef3c7' }}; color: {{ $activity->is_completed ? '#166534' : '#92400e' }}; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; flex-shrink: 0;">
                                        {{ $activity->is_completed ? '✓ Done' : 'Pending' }}
                                    </span>
                                </div>
                            @endforeach
                        @else
                            <div style="padding: 20px; text-align: center; color: #9ca3af; font-size: 12px;">
                                <i class="bi bi-inbox" style="font-size: 24px; display: block; margin-bottom: 8px; opacity: 0.5;"></i>
                                No activities yet. Click "Manage" button to add activities.
                            </div>
                        @endif

                        <!-- Action Buttons -->
                        <div style="display: flex; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                            @if($showRecordButton)
                            <button onclick="openRecordProgressModal('{{ $plan->month_label }}', {{ $plan->planned_progress_percent }}, '{{ $plan->planned_date->toDateString() }}', {{ $latestProgress?->actual_progress_percent ?? 0 }}, {{ $highestActualProgress }}, {{ $loop->last ? '1' : '0' }})" 
                                    style="flex: 1; background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;"
                                    title="Record Monthly Progress"
                                    onmouseover="this.style.background='#c6f6d5'; this.style.borderColor='#68e6a8';"
                                    onmouseout="this.style.background='#dcfce7'; this.style.borderColor='#86efac';">
                                <i class="bi bi-graph-up-arrow"></i> Record Progress
                            </button>
                            @endif
                            @if($latestProgress && (auth()->user()->role === 'admin' || $plan->month_label === $latestProgressMonth))
                            <button onclick="openEditProgressModal({{ $latestProgress->id }}, '{{ $latestProgress->month_label }}', {{ $loop->last ? '1' : '0' }})"
                                    style="flex: 1; background: #dbeafe; color: #0369a1; border: 1px solid #bae6fd; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;"
                                    title="Edit Latest Progress Record"
                                    onmouseover="this.style.background='#bfdbfe'; this.style.borderColor='#7dd3fc';"
                                    onmouseout="this.style.background='#dbeafe'; this.style.borderColor='#bae6fd';">
                                <i class="bi bi-pencil"></i> Edit Progress
                            </button>
                            @endif
                            @if($latestProgress && $incompleteCount > 0 && $hasFutureMonths)
                            <button onclick="openCarryOverForMonth('{{ $plan->month_label }}')"
                                    style="flex: 1; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;"
                                    title="Carry Over Incomplete Activities"
                                    onmouseover="this.style.background='#fde68a';"
                                    onmouseout="this.style.background='#fef3c7';">
                                <i class="bi bi-arrow-right-circle"></i> ↗ Carry Over
                            </button>
                            @endif
                            @if(!$latestProgress)
                            @php
                                $manageMode = ($project->extension_count > 0 && !$latestProgress) ? 'edit' : 'activities-only';
                            @endphp
                            <button onclick="openManageMonthModal({{ $plan->id }}, '{{ $plan->month_label }}', '{{ $plan->planned_date->toDateString() }}', {{ $plan->planned_progress_percent }}, '{{ addslashes($plan->description ?? '') }}', '{{ $manageMode }}', {{ $prevTarget ?? 'null' }}, {{ $nextTarget ?? 'null' }})"
                                    style="flex: 1; background: #f0f0f0; color: #374151; border: 1px solid #d1d5db; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;"
                                    title="Manage Month Target and Activities"
                                    onmouseover="this.style.background='#e5e7eb';"
                                    onmouseout="this.style.background='#f0f0f0';">
                                <i class="bi bi-pencil-square"></i> Manage Month
                            </button>
                            @endif
                            @if(auth()->user()->role === 'admin' || (!$latestProgress && $plan->original_planned_progress_percent === null))
                            <button onclick="deleteMonthTarget({{ $plan->id }})" 
                                    style="flex: 1; background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;"
                                    title="Delete Month Target"
                                    onmouseover="this.style.background='#fecaca'; this.style.borderColor='#f878a8';"
                                    onmouseout="this.style.background='#fee2e2'; this.style.borderColor='#fca5a5';">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <!-- Empty State -->
            <div style="padding: 40px; text-align: center; color: #9ca3af; border: 2px dashed #d1d5db; border-radius: 10px;">
                <i class="bi bi-inbox" style="font-size: 40px; display: block; margin-bottom: 12px; opacity: 0.5;"></i>
                <div style="font-size: 14px; font-weight: 500; margin-bottom: 6px;">No baseline schedule yet</div>
                <div style="font-size: 12px; color: #d1d5db;">Click "Add Month Target" to set up your project's monthly progress targets</div>
            </div>
        @endforelse
    </div>
</div>

<!-- ==================== INFO SECTION ==================== 
<div style="margin-top: 24px; padding: 15px; background: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 6px;">
    <div style="display: flex; align-items: flex-start; gap: 12px;">
        <i class="bi bi-info-circle" style="color: #0369a1; font-size: 18px; margin-top: 2px;"></i>
        <div style="font-size: 13px; color: #0c4a6e;">
            <strong>How it works:</strong>
            <div style="margin-top: 8px; line-height: 1.6;">
                1. Add monthly progress targets (e.g., 20% by Jan, 40% by Feb)<br>
                2. For each month, add specific activities that will be monitored<br>
                3. During monthly inspections, mark activities as complete and record actual progress<br>
                4. System calculates variance between planned and actual progress
            </div>
        </div>
    </div>
</div>
-->

<!-- Include Project Extensions Section (only if baseline exists) -->
@if($hasBaseline)
@include('projects.project_detail.components.tabs.partials.extensions-section')

<!-- Load Extensions JavaScript Utilities -->
<script src="{{ asset('js/admin/projects/baseline-schedule-extensions.js') }}"></script>
@endif

{{--  
<!-- ==================== MONTHLY ACTIVITIES SECTION ==================== -->
<div style="margin-top: 30px;">
    <div style="background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%); color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
            <i class="bi bi-checklist" style="font-size: 20px;"></i> Monthly Activities
        </h3>
    </div>

    <!-- Month Selector -->
    <div style="padding: 15px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; gap: 15px;">
        <label for="activityMonthSelect" style="font-weight: 600; color: #374151; font-size: 13px;">Filter by Month:</label>
        <select id="activityMonthSelect" onchange="loadMonthActivities(this.value)" 
                style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; cursor: pointer;">
            <option value="">— Select a month to view activities —</option>
            @foreach($project->baselineSchedules()->orderBy('planned_date', 'asc')->get() as $schedule)
                <option value="{{ $schedule->month_label }}">{{ $schedule->month_label }} ({{ $schedule->planned_date->format('F') }})</option>
            @endforeach
        </select>
    </div>

    <!-- Activities Table -->
    <div style="overflow-x: auto;">
        <table id="activitiesTable" style="width: 100%; border-collapse: collapse; display: none;">
            <thead>
                <tr style="background: #f3f4f6; border-bottom: 2px solid #d1d5db;">
                    <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Activity Name</th>
                    <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Description</th>
                    <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Status</th>
                    <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Actions</th>
                </tr>
            </thead>
            <tbody id="activitiesTableBody"></tbody>
        </table>

        <!-- Empty State -->
        <div id="activitiesEmpty" style="padding: 40px 20px; text-align: center; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0 0 8px 8px;">
            <div style="font-size: 14px; color: #9ca3af; display: flex; flex-direction: column; align-items: center; gap: 8px;">
                <i class="bi bi-inbox" style="font-size: 32px; opacity: 0.5;"></i>
                <div>Select a month to view its activities</div>
            </div>
        </div>
    </div>
</div> --}}

<script>
function loadMonthActivities(monthLabel) {
    if (!monthLabel) {
        document.getElementById('activitiesTable').style.display = 'none';
        document.getElementById('activitiesEmpty').style.display = 'block';
        return;
    }

    // Fetch activities for this month via AJAX
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
                            <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; background: ${statusBg}; color: ${statusColor};">
                                ${statusText}
                            </span>
                        </td>
                        <td style="padding: 12px 15px; text-align: center; display: flex; justify-content: center; gap: 6px;">
                            <button onclick="toggleActivityCompletion(${activity.id}, ${activity.is_completed ? 0 : 1})"
                                    style="background: ${activity.is_completed ? '#fee2e2' : '#dcfce7'}; color: ${activity.is_completed ? '#dc2626' : '#166534'}; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="bi ${activity.is_completed ? 'bi-x-circle' : 'bi-check-circle'}"></i>
                                ${activity.is_completed ? 'Mark Pending' : 'Mark Done'}
                            </button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        })
        .catch(error => console.error('Error loading activities:', error));
}

function toggleActivityCompletion(activityId, isCompleted) {
    fetch(`/api/project-activities/${activityId}/toggle`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ is_completed: isCompleted })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const monthSelect = document.getElementById('activityMonthSelect');
            const monthLabel = document.getElementById('currentMonthLabel').value;
            
            // Reload the current month's activities
            if (monthLabel) {
                reloadInlineActivities(monthLabel);
            }
            
            // Also refresh modal if it's open
            if (monthSelect && monthSelect.value) {
                loadActivitiesForMonth(monthSelect.value);
            }
        }
    })
    .catch(error => console.error('Error toggling activity:', error));
}
</script>

</div>


@include('components.delete-confirmation-modal')

<!-- MODALS FOR PROJECT PROGRESS TRACKING -->
<!-- Include Add Month Target Modal -->
@include('projects.project_detail.components.modals.add_month_target_modal')

<!-- Set Up Baseline Modal (initial baseline creation — B3.2) -->
@include('projects.project_detail.components.modals._setup_baseline_modal')

<!-- Include Manage Month Modal (merged edit target + manage activities) -->
@include('projects.project_detail.components.modals._manage_month_modal')

<!-- Include Record Progress Modal -->
@include('projects.project_detail.components.modals.record_progress_modal')

<!-- Include Carry Over Activity Modal -->
@include('projects.project_detail.components.modals._carry_over_activity_modal')

<!-- Include Edit Progress Modal -->
@include('projects.project_detail.components.modals._edit_progress_modal')
{{-- toggleActivityCollapse() and animation setup moved to baseline-schedule-utils.js

Add Month Target Modal functions moved to baseline-schedule-add-month-modal.js
- openAddMonthTargetModal()
- setupMonthDateSync()
- addActivityRow()
- removeActivityRow()
- saveMonthTarget()
--}} 

<!-- Load Add Month Target Modal JavaScript -->
<script src="{{ asset('js/admin/projects/baseline-schedule-add-month-modal.js') }}"></script>

<!-- Load Set Up Baseline Modal JavaScript -->
<script src="{{ asset('js/admin/projects/baseline-schedule-setup-modal.js') }}"></script>

<!-- Load S-curve Excel baseline import (Phase 2) -->
<script type="module" src="{{ asset('js/admin/projects/scurve-import-core.js') }}"></script>
<script src="{{ asset('js/admin/projects/scurve-import-ui.js') }}"></script>

<!-- Load S-curve PDF import (Phase 3) -->
<script type="module" src="{{ asset('js/admin/projects/scurve-pdf-parser-core.js') }}"></script>
<script src="{{ asset('js/admin/projects/scurve-pdf-parser.js') }}"></script>

<!-- Load Manage Month Modal JavaScript (includes reloadInlineActivities) -->
<script src="{{ asset('js/admin/projects/baseline-schedule-manage-month-modal.js') }}"></script>

<!-- Phase D: shared progress-attachments helpers (loaded before record/edit modal JS) -->
<script src="{{ asset('js/admin/projects/progress-attachments.js') }}"></script>

<!-- Load Record Progress Modal JavaScript -->
<script src="{{ asset('js/admin/projects/baseline-schedule-record-progress-modal.js') }}"></script>

<!-- Load Carry Over Activity Modal JavaScript -->
<script src="{{ asset('js/admin/projects/baseline-schedule-carry-over.js') }}"></script>

<!-- Load Edit Progress Modal JavaScript -->
<script src="{{ asset('js/admin/projects/baseline-schedule-edit-progress-modal.js') }}"></script>

<script>
// reloadInlineActivities() — now in baseline-schedule-manage-month-modal.js

// Record Progress Modal functions moved to baseline-schedule-record-progress-modal.js
// - openRecordProgressModal()
// - loadActivitiesForProgressModal()
// - saveMonthlyProgress()
// - showRecordProgressErrors()
// - clearRecordProgressErrors()

// Utility functions for page-level operations

/**
 * Reload the baseline schedule section to refresh all month cards
 * This ensures the actual progress and all other data are updated
 */
function reloadMonthCardFromServer(monthLabel) {
    return new Promise((resolve) => {
        console.log('[reloadMonthCardFromServer] Will reload page after 1.5 seconds to show all updates');
        // Save tab state to restore after reload
        sessionStorage.setItem('activeTab', 's-curve-planning');
        // Wait for toast message to be visible, then reload the page
        // This ensures the actual progress percentage updates properly
        setTimeout(() => {
            console.log('[reloadMonthCardFromServer] Reloading page...');
            location.reload();
            resolve();
        }, 1500);
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
    
    fetch(`${buildBaselineScheduleEndpoint()}/${planId}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': token,
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Queue the toast so it displays AFTER the reload (with the page-
            // loading overlay masking the transition) instead of flashing first.
            queueToastAfterReload('Month target deleted successfully!', 'success');
            // Store the active tab before reloading
            sessionStorage.setItem('activeTab', 's-curve-planning');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast('Error: ' + (data.message || 'Failed to delete'), 'error');
            closeDeleteConfirmModal();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error deleting month target: ' + error.message, 'error');
        closeDeleteConfirmModal();
    });
}
</script>

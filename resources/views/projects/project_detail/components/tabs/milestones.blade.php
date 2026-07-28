<!-- MILESTONES TAB -->
<div id="milestones" class="tab-content">

<!-- ==================== PROJECT MILESTONES/TIMELINE SECTION ==================== -->
<div class="milestone-section" id="milestoneSection">
    <div class="milestone-header" style="background: linear-gradient(135deg, #3b82f6, #1e40af); color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
            <i class="bi bi-diagram-3"></i> Project Milestones
        </h3>
        <button class="btn-primary" onclick="openAddMilestoneModal()" style="padding: 8px 16px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.2s;">
            <i class="bi bi-plus-circle"></i> Add Milestone
        </button>
    </div>

    <div class="milestone-summary" id="milestoneSummary">
        <div class="summary-stat">
            <div class="summary-stat-value" id="totalMilestones">{{ $project->milestones->count() }}</div>
            <div class="summary-stat-label">Total Milestones</div>
        </div>
        <div class="summary-stat success">
            <div class="summary-stat-value" id="completedMilestones">{{ $project->milestones->where('status', 'Completed')->count() }}</div>
            <div class="summary-stat-label">Completed</div>
        </div>
        <div class="summary-stat">
            <div class="summary-stat-value" id="inProgressMilestones">{{ $project->milestones->where('status', 'In Progress')->count() }}</div>
            <div class="summary-stat-label">In Progress</div>
        </div>
        <div class="summary-stat danger">
            <div class="summary-stat-value" id="delayedMilestones">{{ $project->milestones->where('status', 'Delayed')->count() }}</div>
            <div class="summary-stat-label">Delayed</div>
        </div>
        <div class="summary-stat warning">
            <div class="summary-stat-value" id="totalWeight">{{ number_format($project->milestones->sum('weight_percentage'), 1) }}%</div>
            <div class="summary-stat-label">Total Weight</div>
        </div>
    </div>

    <!-- Milestones Table -->
    <div class="milestone-table-container">
        <table class="milestone-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th>Milestone Name</th>
                    <th style="width: 80px;">Weight %</th>
                    <th style="width: 130px;">Planned Start</th>
                    <th style="width: 130px;">Planned End</th>
                    <th style="width: 130px;">Actual Start</th>
                    <th style="width: 130px;">Actual End</th>
                    <th style="width: 120px;">Progress</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody id="milestonesTableBody">
                @forelse($project->milestones as $index => $milestone)
                    <tr data-milestone-id="{{ $milestone->id }}">
                        <td>{{ $index + 1 }}</td>
                        <td class="milestone-name">{{ $milestone->name }}</td>
                        <td class="milestone-weight">{{ number_format($milestone->weight_percentage, 2) }}%</td>
                        <td class="milestone-date planned">{{ $milestone->planned_start_date?->format('M d, Y') ?? '-' }}</td>
                        <td class="milestone-date planned">{{ $milestone->planned_end_date?->format('M d, Y') ?? '-' }}</td>
                        <td class="milestone-date actual">{{ $milestone->actual_start_date?->format('M d, Y') ?? '-' }}</td>
                        <td class="milestone-date actual">{{ $milestone->actual_end_date?->format('M d, Y') ?? '-' }}</td>
                        <td class="milestone-progress-cell">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span>{{ number_format($milestone->progress_percentage, 0) }}%</span>
                            </div>
                            <div class="mini-progress-bar">
                                <div class="mini-progress-fill {{ $milestone->progress_percentage >= 100 ? 'completed' : ($milestone->is_delayed ? 'delayed' : '') }}" 
                                     style="width: {{ $milestone->progress_percentage }}%;"></div>
                            </div>
                        </td>
                        <td>
                            <span class="milestone-status {{ strtolower(str_replace(' ', '-', $milestone->status)) }}">
                                {{ $milestone->status }}
                            </span>
                        </td>
                        <td class="milestone-actions">
                            <button class="milestone-btn success" onclick="openUpdateProgressModal({{ $milestone->id }})" title="Update Progress" style="background: rgba(16,185,129,0.1); color: #10b981;">
                                <i class="bi bi-graph-up-arrow"></i>
                            </button>
                            <button class="milestone-btn" onclick="openEditMilestoneModal({{ $milestone->id }})" title="Edit Milestone">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="milestone-btn danger" onclick="deleteMilestone({{ $milestone->id }})" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr id="emptyMilestonesRow">
                        <td colspan="10">
                            <div class="empty-milestones">
                                <div class="empty-milestones-icon">📋</div>
                                <div style="font-size: 16px; font-weight: 600; margin-bottom: 8px;">No Milestones Yet</div>
                                <div>Add milestones to track project timeline and progress</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Visual Gantt Chart -->
    @if($project->milestones->count() > 0)
    <div class="gantt-container">
        <div class="gantt-header">
            <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Timeline Visualization</h3>
        </div>
        <div class="gantt-chart" id="ganttChart">
            @php
                $allDates = $project->milestones->flatMap(function($m) {
                    return [$m->planned_start_date, $m->planned_end_date, $m->actual_start_date, $m->actual_end_date];
                })->filter()->sort();
                
                $minDate = $allDates->first() ?? $project->planned_start_date ?? now();
                $maxDate = $allDates->last() ?? $project->planned_end_date ?? now()->addMonths(3);
                $totalDays = max($minDate->diffInDays($maxDate), 1);
            @endphp
            
            @foreach($project->milestones as $milestone)
                <div class="gantt-row">
                    <div class="gantt-label" title="{{ $milestone->name }}">
                        {{ Str::limit($milestone->name, 25) }}
                        <span style="color: var(--primary); font-size: 11px;">({{ number_format($milestone->weight_percentage, 1) }}%)</span>
                    </div>
                    <div class="gantt-bars">
                        @if($milestone->planned_start_date && $milestone->planned_end_date)
                            @php
                                $plannedStart = max(0, $minDate->diffInDays($milestone->planned_start_date));
                                $plannedWidth = max(1, $milestone->planned_start_date->diffInDays($milestone->planned_end_date) + 1);
                                $plannedLeft = ($plannedStart / $totalDays) * 100;
                                $plannedWidthPercent = ($plannedWidth / $totalDays) * 100;
                            @endphp
                            <div class="gantt-bar planned" 
                                 style="left: {{ $plannedLeft }}%; width: {{ min($plannedWidthPercent, 100 - $plannedLeft) }}%;"
                                 title="Planned: {{ $milestone->planned_start_date->format('M d') }} - {{ $milestone->planned_end_date->format('M d, Y') }}">
                            </div>
                        @endif
                        @if($milestone->actual_start_date)
                            @php
                                $actualStart = max(0, $minDate->diffInDays($milestone->actual_start_date));
                                $actualEnd = $milestone->actual_end_date ?? now();
                                $actualWidth = max(1, $milestone->actual_start_date->diffInDays($actualEnd) + 1);
                                $actualLeft = ($actualStart / $totalDays) * 100;
                                $actualWidthPercent = ($actualWidth / $totalDays) * 100;
                                $barClass = $milestone->status === 'Completed' ? 'completed' : ($milestone->is_delayed ? 'delayed' : '');
                            @endphp
                            <div class="gantt-bar actual {{ $barClass }}" 
                                 style="left: {{ $actualLeft }}%; width: {{ min($actualWidthPercent, 100 - $actualLeft) }}%;"
                                 title="Actual: {{ $milestone->actual_start_date->format('M d') }} - {{ $actualEnd->format('M d, Y') }}">
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div style="display: flex; gap: 20px; margin-top: 15px; font-size: 12px; color: var(--text-muted);">
            <span><span style="display: inline-block; width: 20px; height: 10px; background: rgba(37,99,235,0.3); border: 1px dashed var(--primary); border-radius: 2px; margin-right: 5px;"></span> Planned</span>
            <span><span style="display: inline-block; width: 20px; height: 10px; background: var(--primary); border-radius: 2px; margin-right: 5px;"></span> Actual</span>
            <span><span style="display: inline-block; width: 20px; height: 10px; background: var(--success); border-radius: 2px; margin-right: 5px;"></span> Completed</span>
            <span><span style="display: inline-block; width: 20px; height: 10px; background: var(--danger); border-radius: 2px; margin-right: 5px;"></span> Delayed</span>
        </div>
    </div>
    @endif
</div>

</div>
<!-- END MILESTONES TAB CONTENT -->

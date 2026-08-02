<!-- OVERVIEW TAB CONTENT safe to consolidate to this -->
@php
    $overviewProgress = $data['overview']['progress'] ?? 0;
    $projectStatus = $data['status'] ?? [];
    $baselineSchedules = $project->baselineSchedules()->orderBy('planned_date')->get();
    $activities = $project->activities()->orderBy('month_label')->get();
    $latestProgressHistory = $project->progressHistory()
        ->orderByDesc('month_label')
        ->orderByDesc('inspection_date')
        ->orderByDesc('created_at')
        ->first();

    $totalActivities = $data['overview']['totalActivities'] ?? $activities->count();
    $completedActivities = $data['overview']['completedActivities'] ?? $activities->where('is_completed', true)->count();
    $pendingActivities = max(0, $totalActivities - $completedActivities);
    $activityCompletionRate = $data['overview']['activityCompletionRate'] ?? ($totalActivities > 0 ? round(($completedActivities / $totalActivities) * 100, 1) : 0);
    $latestProgressValue = $data['overview']['latestProgressValue'] ?? null;
    $latestProgressDate = $data['overview']['latestProgressDate'] ?? null;
    $latestProgressLabel = $latestProgressDate ? $latestProgressDate->format('M d, Y') : 'No monthly record yet';

    // Project Information variables (V2 style)
    $plannedStartDisplay = $project->planned_start_date
        ? $project->planned_start_date->format('M d, Y')
        : 'Not set';

    $plannedEndDisplay = $project->planned_end_date
        ? $project->planned_end_date->format('M d, Y')
        : 'Not set';

    $actualStartDisplay = $project->actual_start_date
        ? $project->actual_start_date->format('M d, Y')
        : 'Not yet recorded';

    $actualEndDisplay = $project->actual_end_date
        ? $project->actual_end_date->format('M d, Y')
        : 'Not yet recorded';

    $plannedDurationDisplay = $project->planned_duration_days
        ? $project->planned_duration_days . ' day(s)'
        : 'Not set';

    $contractDurationDisplay = $project->contract_duration_days
        ? $project->contract_duration_days . ' day(s)'
        : 'Not set';

    $handoverDateDisplay = $project->planned_handing_over_date
        ? \Carbon\Carbon::parse($project->planned_handing_over_date)->format('M d, Y')
        : 'Not set';

    $originalPlannedEndDisplay = $project->original_planned_end_date
        ? \Carbon\Carbon::parse($project->original_planned_end_date)->format('M d, Y')
        : 'Not set';

    $extensionCountDisplay = (int) ($project->extension_count ?? 0);

    $managerContactDisplay = !empty($project->project_manager_contact)
        ? $project->project_manager_contact
        : 'Not set';

    $estimatedWorkersDisplay = $project->estimated_workers
        ? number_format((int) $project->estimated_workers) . ' estimated'
        : 'Not set';

    $totalBudgetDisplay = $project->approved_budget !== null
        ? ('PHP ' . number_format((float) $project->approved_budget, 2))
        : 'Not set';

    $plannedTimelineDisplay = ($project->planned_start_date || $project->planned_end_date)
        ? ($plannedStartDisplay . ' to ' . $plannedEndDisplay)
        : 'Not set';

    $actualTimelineDisplay = ($project->actual_start_date || $project->actual_end_date)
        ? ($actualStartDisplay . ' to ' . $actualEndDisplay)
        : 'Not yet recorded';

    $nextTarget = null;
    foreach ($baselineSchedules as $schedule) {
        if ($schedule->planned_date >= now()) {
            $nextTarget = $schedule;
            break;
        }
    }
    if (!$nextTarget && $baselineSchedules->isNotEmpty()) {
        $nextTarget = $baselineSchedules->first();
    }

    $recentActivity = $data['recent_activity'] ?? collect();
    $alerts = $data['alerts'] ?? [];
    $openIssues = $data['open_issues'] ?? ['total_open' => 0, 'worst_severity' => null, 'aging_count' => 0, 'items' => []];
    $totalDocuments = $data['total_documents'] ?? 0;
    $latestDocuments = collect($data['documents'] ?? [])->take(3);
@endphp

<div id="overview" class="tab-content active">
    <div style="padding: 20px;">
        <!-- Project Information - Full Width -->
        <div style="margin-bottom: 20px;">
            <div class="card">
                <div class="card-header"><span class="card-title">Project Information(shared)</span><span class="card-action">Single source</span></div>
                <div style="padding: 16px;">
                    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px;">
                        <!-- Project Profile Block -->
                        <section style="border: 1px solid #e5e7eb; border-radius: 10px; background: #f8fafc; padding: 12px;">
                            <h4 style="margin: 0; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-bottom: 12px;">Project Profile</h4>
                            <div style="display: grid; gap: 0;">
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Project Code</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $project->code ?? 'N/A' }}</div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Category</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $project->category?->name ?? 'N/A' }}</div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Location</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $project->location ?? 'N/A' }}</div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Contractor</div>
                                    <div>
                                        <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $project->contractor?->company_name ?: ($project->contractor?->contractor_name ?: ($project->contractor?->user?->name ?? 'Not set')) }}</div>
                                        <div style="margin-top: 3px; font-size: 11px; color: #6b7280; line-height: 1.35; word-break: break-word;">{{ $project->contractor?->contractor_name ?: ($project->contractor?->user?->name ?? 'No representative') }} @if(!empty($project->contractor?->contact_number))| {{ $project->contractor->contact_number }}@endif</div>
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Project Manager</div>
                                    <div>
                                        <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $project->getProjectManagerName() ?? 'Not set' }}</div>
                                        <div style="margin-top: 3px; font-size: 11px; color: #6b7280; line-height: 1.35; word-break: break-word;">{{ $managerContactDisplay }}</div>
                                    </div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Estimated Workforce</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $estimatedWorkersDisplay }}</div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Total Budget</div>
                                    <div>
                                        <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $totalBudgetDisplay }}</div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Schedule Summary Block -->
                        <section style="border: 1px solid #e5e7eb; border-radius: 10px; background: #f8fafc; padding: 12px;">
                            <h4 style="margin: 0; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-bottom: 12px;">Schedule Summary</h4>
                            <div style="display: grid; gap: 0;">
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Planned Timeline</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $plannedTimelineDisplay }}</div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Actual Timeline</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $actualTimelineDisplay }}</div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Contract Duration</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $plannedDurationDisplay }}</div>
                                </div>
                                  
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Actual Duration</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $contractDurationDisplay }}</div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Planned Handover</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $handoverDateDisplay }}</div>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Original Planned End</div>
                                    <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $originalPlannedEndDisplay }}</div>
                                </div>
                                <div style="display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 10px; padding: 8px 0;">
                                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; line-height: 1.4;">Current Planned End</div>
                                    <div>
                                        <div style="font-size: 13px; font-weight: 700; color: #111827; line-height: 1.35; word-break: break-word;">{{ $plannedEndDisplay }}</div>
                                        <div style="margin-top: 3px; font-size: 11px; color: #6b7280; line-height: 1.35; word-break: break-word;">Extensions: {{ $extensionCountDisplay }}</div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    @if(!empty($project->description))
                        <details style="border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; padding: 10px 12px;">
                            <summary style="cursor: pointer; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280;">Project Description</summary>
                            <p style="margin: 8px 0 0; font-size: 13px; color: #111827; line-height: 1.55; white-space: pre-line;">{{ $project->description }}</p>
                        </details>
                    @endif
                </div>
            </div>
        </div>

        <!-- AI ANALYSIS SECTION -->
        @if(!empty($data['ai_prediction']) && count($data['ai_prediction']) > 0)
            @include('projects.project_detail.components.tabs.ai-prediction', ['project' => $project, 'prediction' => $data['ai_prediction']])
        @endif

        <!-- Remaining Cards in Grid -->
        <div class="content-grid">
            <div class="left-col">
                <div class="card">
                    <div class="card-header"><span class="card-title">Monthly Progress Snapshot</span><span class="card-action">Checklist model</span></div>
                    <div style="padding: 16px;">
                        <div style="display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px;">
                            <div><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Overall Progress</div><div style="font-size: 26px; font-weight: 800; color: #111827; margin-top: 4px;">{{ number_format($overviewProgress, 1) }}%</div></div>
                            <div><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Latest Update</div><div style="font-size: 14px; font-weight: 700; color: #111827; margin-top: 4px;">{{ $latestProgressLabel }}</div></div>
                        </div>
                        <div style="height: 12px; background: #e5e7eb; border-radius: 999px; overflow: hidden;"><div style="height: 100%; width: {{ min(100, max(0, $overviewProgress)) }}%; background: linear-gradient(90deg, #3b82f6, #10b981); border-radius: 999px;"></div></div>
                        <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 14px;">
                            <div style="padding: 12px; background: #f8fafc; border-radius: 10px;"><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Planned</div><div style="font-size: 18px; font-weight: 800; color: #2563eb; margin-top: 4px;">{{ number_format($projectStatus['planned_percent'] ?? 0, 1) }}%</div></div>
                            <div style="padding: 12px; background: #f8fafc; border-radius: 10px;"><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Actual</div><div style="font-size: 18px; font-weight: 800; color: #059669; margin-top: 4px;">{{ number_format($projectStatus['actual_percent'] ?? 0, 1) }}%</div></div>
                            <div style="padding: 12px; background: #f8fafc; border-radius: 10px;"><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Variance</div><div style="font-size: 18px; font-weight: 800; color: {{ ($projectStatus['variance'] ?? 0) >= 0 ? '#10b981' : '#dc2626' }}; margin-top: 4px;">{{ ($projectStatus['variance'] ?? 0) >= 0 ? '+' : '' }}{{ number_format($projectStatus['variance'] ?? 0, 1) }}%</div></div>
                        </div>
                        <div style="margin-top: 14px; padding: 12px; background: rgba(59, 130, 246, 0.06); border-radius: 10px; font-size: 13px; color: var(--text-muted); line-height: 1.6;">{{ $projectStatus['message'] ?? 'No schedule status available.' }}</div>
                        @if($nextTarget)
                            <div style="margin-top: 12px; font-size: 12px; color: var(--text-muted);"><strong style="color: #111827;">Next target:</strong> {{ $nextTarget->month_label }} on {{ $nextTarget->planned_date->format('M d, Y') }} at {{ number_format($nextTarget->planned_progress_percent, 1) }}%</div>
                        @endif
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><span class="card-title">Issues & Risks</span><span class="card-action">{{ $openIssues['total_open'] }} open</span></div>
                    <div style="padding: 16px; display: grid; gap: 10px;">
                        @forelse($openIssues['items'] as $issue)
                        <div style="display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding: 12px; border: 1px solid #e5e7eb; border-radius: 10px; background: #fafafa;">
                            <div><div style="font-weight: 700; color: #111827;">{{ $issue['issue_name'] }}</div><div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">{{ $issue['severity'] }} · Open for {{ $issue['days_open'] }} day{{ $issue['days_open'] == 1 ? '' : 's' }}</div></div>
                            <span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px; background: rgba(0,0,0,0.04); color: {{ $issue['badge_color'] }}; font-size: 11px; font-weight: 700; white-space: nowrap;">{{ $issue['severity'] }}</span>
                        </div>
                        @empty
                        <div style="font-size: 13px; color: var(--text-muted); padding: 8px 0;">No open issues — good to go.</div>
                        @endforelse
                        @if($openIssues['total_open'] > 0)
                        <button type="button" onclick="switchMainTab('issues', event)" style="margin-top: 4px; padding: 8px 0; border: none; background: none; cursor: pointer; color: var(--primary); font-size: 12px; font-weight: 700; text-align: left;">View all {{ $openIssues['total_open'] }} open issues →</button>
                        @endif
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><span class="card-title">Notifications & Alerts</span><span class="card-action">{{ count($alerts) }} active</span></div>
                    <div style="padding: 16px; display: grid; gap: 10px;">
                        @forelse($alerts as $alert)
                        <div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px; border-radius: 10px; background: #fffaf2; border: 1px solid rgba(245, 158, 11, 0.15);">
                            <div style="width: 10px; height: 10px; border-radius: 50%; margin-top: 5px; background: {{ $alert['color'] }};"></div>
                            <div><div style="font-weight: 700; color: #111827;">{{ $alert['title'] }}</div><div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">{{ $alert['meta'] }}</div></div>
                        </div>
                        @empty
                        <div style="font-size: 13px; color: var(--text-muted); padding: 8px 0;">No active alerts.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="right-col">
                <div class="card">
                    <div class="card-header"><span class="card-title">Baseline Highlights</span><span class="card-action">Monthly targets</span></div>
                    <div style="padding: 16px; display: grid; gap: 10px;">
                        <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 12px; background: #f8fafc; border-radius: 10px;"><div><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Monthly Targets</div><div style="font-size: 20px; font-weight: 800; color: #111827; margin-top: 4px;">{{ $baselineSchedules->count() }}</div></div><i class="bi bi-calendar-check" style="font-size: 22px; color: #3b82f6;"></i></div>
                        <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 12px; background: #f8fafc; border-radius: 10px;"><div><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Checklist Completion</div><div style="font-size: 20px; font-weight: 800; color: #111827; margin-top: 4px;">{{ number_format($activityCompletionRate, 1) }}%</div></div><i class="bi bi-list-check" style="font-size: 22px; color: #10b981;"></i></div>
                        <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 12px; background: #f8fafc; border-radius: 10px;"><div><div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Pending Activities</div><div style="font-size: 20px; font-weight: 800; color: #111827; margin-top: 4px;">{{ $pendingActivities }}</div></div><i class="bi bi-hourglass-split" style="font-size: 22px; color: #f59e0b;"></i></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><span class="card-title">Documents</span><span class="card-action">{{ $totalDocuments }} total</span></div>
                    <div class="doc-list" style="padding: 16px; display: grid; gap: 10px;">
                        @forelse($latestDocuments as $document)
                        <div class="doc-item" style="cursor: default;">
                            <div class="doc-icon" style="background:rgba(59,130,246,0.10)"><svg viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                            <div class="doc-info"><div class="doc-name">{{ $document->document_name }}</div><div class="doc-meta">{{ number_format($document->file_size, 1) }} KB · {{ $document->created_at?->format('M d, Y') }}</div></div>
                        </div>
                        @empty
                        <div style="font-size: 13px; color: var(--text-muted); padding: 8px 0;">No documents uploaded yet.</div>
                        @endforelse
                        @if($totalDocuments > 0)
                        <button type="button" onclick="switchMainTab('documents', event)" style="margin-top: 4px; padding: 8px 0; border: none; background: none; cursor: pointer; color: var(--primary); font-size: 12px; font-weight: 700; text-align: left;">All {{ $totalDocuments }} documents →</button>
                        @endif
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><span class="card-title">Recent Activity</span><span class="card-action">Live</span></div>
                    <div class="activity-list" style="padding: 16px;">
                        @forelse($recentActivity as $activity)
                        <div class="activity-item"><div class="activity-line"><div class="activity-dot" style="background:var(--primary)"></div><div class="activity-tail"></div></div><div class="activity-content"><div class="activity-text"><strong>{{ $activity->action }}</strong> — {{ $activity->description }}</div><div class="activity-time">{{ $activity->user?->name ?? 'System' }} · {{ $activity->created_at?->diffForHumans() }}</div></div></div>
                        @empty
                        <div style="font-size: 13px; color: var(--text-muted); padding: 8px 0;">No activity yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- END OVERVIEW TAB CONTENT -->

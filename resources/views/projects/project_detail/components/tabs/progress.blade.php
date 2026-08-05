<!-- PROGRESS TAB -->
<div id="progress" class="tab-content">

<!-- ==================== PROJECT PROGRESS CHART ==================== -->
{{-- Chart data is now loaded lazily via AJAX when the Progress tab is first clicked. --}}
{{-- See loadProgressChartData() in the <script> block below. --}}

<!-- PROJECT PROGRESS CHART -->
@php($reportRole = auth()->user()->role)
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
        <span class="card-title">
            <i class="bi bi-graph-up" style="color: var(--primary);"></i> Project Progress (Planned vs Actual)
        </span>
        <span style="display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 12px; color: var(--text-muted);">
                <i class="bi bi-info-circle"></i> Cumulative project progress over time
            </span>
            <span class="dropdown">
                <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download"></i> Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-header">Reports</span></li>
                    <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.summary', $project->id) }}">
                        <i class="bi bi-file-earmark-pdf"></i> Project Summary (PDF)</a></li>
                    <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.progress', $project->id) }}">
                        <i class="bi bi-file-earmark-excel"></i> Progress History (Excel)</a></li>
                    <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.baseline', $project->id) }}">
                        <i class="bi bi-file-earmark-excel"></i> Baseline Schedule (Excel)</a></li>
                    <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.extensions', $project->id) }}">
                        <i class="bi bi-file-earmark-excel"></i> Extensions (Excel)</a></li>
                    <li><a class="dropdown-item" href="{{ route($reportRole . '.projects.exports.issues', $project->id) }}">
                        <i class="bi bi-file-earmark-excel"></i> Issues (Excel)</a></li>
                </ul>
            </span>
        </span>
    </div>
    <div style="padding: 20px;">

        {{-- Chart container — JS writes spinner, then canvas, or error message --}}
        <div id="scurveChartContainer" style="position: relative; height: 350px; margin-bottom: 20px;">
            <div style="display:flex; align-items:center; justify-content:center; height:100%; color:#9ca3af; font-size:13px; gap:8px;">
                <i class="bi bi-graph-up" style="font-size:20px;"></i>
                <span>Chart will load when you visit this tab.</span>
            </div>
        </div>

        {{-- Legend — items shown/hidden by JS after AJAX response --}}
        <div style="display: flex; justify-content: center; gap: 25px; margin-bottom: 15px; flex-wrap: wrap; font-size: 12px;">
            <div id="legendFrozenPlan" style="display: flex; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #3b82f6; border-radius: 2px;"></div>
                <span>Original Plan</span>
            </div>
            <div id="legendActual" style="display: none; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #10b981; border-radius: 2px;"></div>
                <span>Actual Progress</span>
            </div>
            <div id="legendAdjustedPlan" style="display: none; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #f59e0b; border-radius: 2px;"></div>
                <span>Adjusted Plan</span>
            </div>
            <div id="legendPolyForecast" style="display: none; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #a855f7; border-radius: 2px; border-top: 2px dashed #a855f7;"></div>
                <span>Near-Term Forecast (Polynomial)</span>
            </div>
        </div>

        {{-- Monthly summary table — rows built by JS after AJAX response --}}
        <div id="monthlySummaryWrapper" style="display:none; margin-top: 25px; padding: 15px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px;">
            <h6 style="margin: 0 0 15px 0; font-size: 13px; font-weight: 600; text-transform: uppercase; color: var(--text-muted);">
                <i class="bi bi-table"></i> Monthly Progress Summary
            </h6>
            <div style="overflow-x: auto; overflow-y: auto; max-height: 320px; border: 1px solid #e5e7eb; border-radius: 6px; background: white;">
                <style>
                    .monthly-progress-table::-webkit-scrollbar { width: 8px; height: 8px; }
                    .monthly-progress-table::-webkit-scrollbar-track { background: #f1f5f9; }
                    .monthly-progress-table::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
                    .monthly-progress-table::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
                </style>
                <table style="width: 100%; border-collapse: collapse; font-size: 12px;" class="monthly-progress-table">
                    <thead style="position: sticky; top: 0; z-index: 10; background: white;">
                        <tr style="background: white; border-bottom: 2px solid var(--border);">
                            <th style="padding: 10px 12px; text-align: left; font-weight: 600; color: var(--text-muted);">Month</th>
                            <th style="padding: 10px 12px; text-align: center; font-weight: 600; color: var(--text-muted);">Planned %</th>
                            <th style="padding: 10px 12px; text-align: center; font-weight: 600; color: var(--text-muted);">Actual %</th>
                            <th style="padding: 10px 12px; text-align: center; font-weight: 600; color: var(--text-muted);">Variance</th>
                            <th style="padding: 10px 12px; text-align: center; font-weight: 600; color: var(--text-muted);">Status</th>
                            <th style="padding: 10px 12px; text-align: center; font-weight: 600; color: var(--text-muted);">Activities</th>
                        </tr>
                    </thead>
                    <tbody id="monthlySummaryBody"></tbody>
                </table>
            </div>
        </div>

        <!-- ==================== PROGRESS RECORDING HISTORY ==================== -->
        @php $progressHistoryRecords = $project->progressHistory->load('attachments')->sortByDesc(fn($h) => $h->month_label . '|' . ($h->inspection_date ?? '0000-00-00') . '|' . str_pad($h->id, 12, '0', STR_PAD_LEFT)); @endphp
        @can('update', $project)
        <div style="margin-top: 40px;">
            <div style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                    <i class="bi bi-clock-history" style="font-size: 20px;"></i> Progress Recording History
                </h3>
            </div>

            <div style="overflow-x: auto; overflow-y: auto; max-height: 320px; border: 1px solid #e5e7eb; border-radius: 0 0 8px 8px; background: white;">
                <style>
                    .progress-history-table::-webkit-scrollbar {
                        width: 8px;
                        height: 8px;
                    }
                    .progress-history-table::-webkit-scrollbar-track {
                        background: #f1f5f9;
                    }
                    .progress-history-table::-webkit-scrollbar-thumb {
                        background: #cbd5e1;
                        border-radius: 4px;
                    }
                    .progress-history-table::-webkit-scrollbar-thumb:hover {
                        background: #94a3b8;
                    }
                </style>
                @if($progressHistoryRecords->count() > 0)
                    <table style="width: 100%; border-collapse: collapse;" class="progress-history-table">
                        <thead style="position: sticky; top: 0; z-index: 10; background: #f3f4f6;">
                            <tr style="border-bottom: 2px solid #d1d5db;">
                                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Recording Date</th>
                                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Inspection Date</th>
                                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Month</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Planned %</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Actual %</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Variance</th>
                                @if(auth()->user()->role === 'admin')
                                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Recorded By</th>
                                @endif
                                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Notes</th>
                                <th style="padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Attachments</th>
                                <th style="padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($progressHistoryRecords as $history)
                                @php
                                    // Use actual database columns
                                    $actualProgress = $history->actual_progress_percent ?? $history->progress_percentage ?? 0;
                                    $plannedProgress = $history->planned_progress_percent ?? 0;
                                    $variance = $actualProgress - $plannedProgress;
                                    $varianceClass = $variance >= 0 ? 'text-success' : 'text-danger';
                                    $varianceIcon = $variance >= 0 ? 'arrow-up' : 'arrow-down';
                                    $varianceBg = $variance >= 0 ? 'rgba(16, 185, 129, 0.1)' : 'rgba(220, 38, 38, 0.1)';
                                    $varianceColor = $variance >= 0 ? '#10b981' : '#dc2626';
                                @endphp
                                <tr style="border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s;">
                                    <td style="padding: 12px 15px; font-weight: 600; color: #111827;">
                                        <div>{{ $history->created_at->format('M d, Y') }}</div>
                                        <div style="font-size: 11px; color: #9ca3af; margin-top: 2px;">{{ $history->created_at->format('h:i A') }}</div>
                                    </td>
                                    <td style="padding: 12px 15px; color: #374151; font-weight: 500;">
                                        @php
                                            $inspDate = $history->inspection_date ?? null;
                                        @endphp
                                        @if($inspDate)
                                            <div>{{ \Carbon\Carbon::parse($inspDate)->format('M d, Y') }}</div>
                                            <div style="font-size: 11px; color: #9ca3af; margin-top: 2px;">{{ \Carbon\Carbon::parse($inspDate)->format('l') }}</div>
                                        @else
                                            <span style="color: #9ca3af;">—</span>
                                        @endif
                                    </td>
                                    <td style="padding: 12px 15px; color: #374151; font-weight: 500;">
                                        @if($history->month_label)
                                            @php
                                                $parts = explode('-', $history->month_label);
                                                $monthName = date('F', mktime(0, 0, 0, (int)$parts[1], 1));
                                                echo $monthName . ' ' . $parts[0];
                                            @endphp
                                        @else
                                            {{ $history->created_at->format('F Y') }}
                                        @endif
                                    </td>
                                    <td style="padding: 12px 15px; text-align: center; font-weight: 600; color: #3b82f6;">
                                        {{ $plannedProgress }}%
                                    </td>
                                    <td style="padding: 12px 15px; text-align: center; font-weight: 600; color: #10b981;">
                                        {{ $actualProgress }}%
                                    </td>
                                    <td style="padding: 12px 15px; text-align: center;">
                                        <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; background: {{ $varianceBg }}; color: {{ $varianceColor }};">
                                            <i class="bi bi-{{ $varianceIcon }}" style="margin-right: 4px;"></i>{{ $variance > 0 ? '+' : '' }}{{ $variance }}%
                                        </span>
                                    </td>
                                    @if(auth()->user()->role === 'admin')
                                    <td style="padding: 12px 15px; color: #6b7280;">
                                        @if($history->recordedBy)
                                            <div style="font-weight: 500; color: #111827;">{{ $history->recordedBy->name }}</div>
                                            <div style="font-size: 11px; color: #9ca3af;">{{ $history->recordedBy->email }}</div>
                                        @else
                                            <span style="color: #9ca3af;">— System</span>
                                        @endif
                                    </td>
                                    @endif
                                    <td style="padding: 12px 15px; color: #6b7280; font-size: 13px; max-width: 250px; word-break: break-word;">
                                        {{ $history->remarks ?? '—' }}
                                    </td>
                                    <td style="padding: 12px 15px;">
                                        @if($history->attachments->count() > 0)
                                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                                @foreach($history->attachments as $att)
                                                    <span class="attachment-chip me-1 mb-1" title="{{ $att->original_name }}"
                                                          onclick="openDocumentViewer({id: {{ $att->id }}, name: '{{ addslashes($att->original_name) }}', type: 'Inspection Evidence', size: '{{ $att->display_size }}', uploadedBy: '{{ addslashes($att->uploaded_by ?? '') }}', uploadedAt: '{{ $att->created_at ? $att->created_at->format('M d, Y H:i') : '' }}', viewUrl: '/{{ auth()->user()->role }}/attachments/{{ $att->id }}/view', downloadUrl: '/{{ auth()->user()->role }}/attachments/{{ $att->id }}/download'})">
                                                        <i class="bi {{ $att->file_extension === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image' }}"></i>
                                                        <span>{{ $att->original_name }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span style="color: #9ca3af;">—</span>
                                        @endif
                                    </td>
                                    <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                                        <button onclick="openEditProgressModal({{ $history->id }}, '{{ $history->month_label }}')"
                                                style="background: #dbeafe; color: #0369a1; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; margin-right: 4px;"
                                                title="Edit Record">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        @if(auth()->user()->role === 'admin' && $loop->first)
                                        <button onclick="deleteProgressRecord({{ $history->id }})"
                                                style="background: #fee2e2; color: #dc2626; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;"
                                                title="Delete Record (only the latest record can be deleted)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div style="padding: 40px 20px; text-align: center; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0 0 8px 8px;">
                        <div style="font-size: 14px; color: #9ca3af; display: flex; flex-direction: column; align-items: center; gap: 8px;">
                            <i class="bi bi-inbox" style="font-size: 32px; opacity: 0.5;"></i>
                            <div>No progress recording history yet</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        @endcan

        <!-- ==================== INSIGHTS SECTION ==================== -->
        <div style="margin-top: 30px;">
            <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                    <i class="bi bi-graph-up" style="font-size: 20px;"></i> Progress Insights
                </h3>
            </div>

            <div style="padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0 0 8px 8px;">
                @php
                    $progressRecords = $project->progressHistory; // no parentheses = uses eager loaded data
                    $totalRecordings = $progressRecords->count();
                    $avgPlanned = $progressRecords->avg('planned_progress_percent') ?? 0;
                    $avgActual  = $progressRecords->avg('actual_progress_percent') ?? 0;
                    // Fallback: if those are null, use progress_percentage
                    if ($avgPlanned == 0 && $avgActual == 0) {
                        $avgActual = $progressRecords->avg('progress_percentage') ?? 0;
                        $avgPlanned = $avgActual; // default to same value if separate columns don't exist
                    }
                    $onTrack = $totalRecordings > 0 && $avgActual >= $avgPlanned;
                @endphp

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <!-- Total Recordings -->
                    <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6;">
                        <div style="font-size: 11px; font-weight: 600; color: #9ca3af; text-transform: uppercase;">Total Recordings</div>
                        <div style="font-size: 24px; font-weight: 700; color: #111827; margin-top: 8px;">{{ $totalRecordings }}</div>
                    </div>

                    <!-- Average Planned -->
                    <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6;">
                        <div style="font-size: 11px; font-weight: 600; color: #9ca3af; text-transform: uppercase;">Avg Planned Progress</div>
                        <div style="font-size: 24px; font-weight: 700; color: #111827; margin-top: 8px;">{{ round($avgPlanned ?? 0) }}%</div>
                    </div>

                    <!-- Average Actual -->
                    <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid #10b981;">
                        <div style="font-size: 11px; font-weight: 600; color: #9ca3af; text-transform: uppercase;">Avg Actual Progress</div>
                        <div style="font-size: 24px; font-weight: 700; color: #111827; margin-top: 8px;">{{ round($avgActual ?? 0) }}%</div>
                    </div>

                    <!-- Status Badge -->
                    <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid {{ $onTrack ? '#10b981' : '#ef4444' }};">
                        <div style="font-size: 11px; font-weight: 600; color: #9ca3af; text-transform: uppercase;">Overall Status</div>
                        <div style="font-size: 14px; font-weight: 700; color: #111827; margin-top: 8px; display: flex; align-items: center; gap: 8px;">
                            <i class="bi bi-{{ $onTrack ? 'check-circle-fill' : 'exclamation-circle-fill' }}" style="color: {{ $onTrack ? '#10b981' : '#ef4444' }}; font-size: 18px;"></i>
                            {{ $onTrack ? 'On Track' : 'Behind Schedule' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== COMPLETION FORECAST S-CURVE (CHART 2) ==================== -->
<div class="card" id="completionForecastSection" style="margin-bottom: 20px; display: none;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span class="card-title">
            <i class="bi bi-graph-up" style="color: var(--primary);"></i> Completion Forecast S-Curve
        </span>
        <span style="font-size: 12px; color: var(--text-muted);">
            <i class="bi bi-info-circle"></i> Shifted planned baselines based on multi-method completion estimates
        </span>
    </div>
    <div style="padding: 20px;">
        <div id="completionForecastChartContainer" style="position: relative; height: 350px; margin-bottom: 20px;">
            <div style="display:flex; align-items:center; justify-content:center; height:100%; color:#9ca3af; font-size:13px; gap:8px;">
                <i class="bi bi-graph-up" style="font-size:20px;"></i>
                <span>Loading completion forecast...</span>
            </div>
        </div>
        {{-- Legend — items shown/hidden by JS after AJAX response --}}
        <div style="display: flex; justify-content: center; gap: 25px; margin-bottom: 15px; flex-wrap: wrap; font-size: 12px;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #3b82f6; border-radius: 2px;"></div>
                <span>Original Plan</span>
            </div>
            <div id="legendAdjPlanCF" style="display: none; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #f59e0b; border-radius: 2px;"></div>
                <span>Adjusted Plan</span>
            </div>
            <div id="legendSA" style="display: none; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #8b5cf6; border-radius: 2px; border-top: 2px dashed #8b5cf6;"></div>
                <span id="legendSALabel">Schedule-Aware Forecast</span>
            </div>
            <div id="legendEAC" style="display: none; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #f97316; border-radius: 2px; border-top: 2px dotted #f97316;"></div>
                <span id="legendEACLabel">EAC_time Forecast</span>
            </div>
        </div>
    </div>
</div>

<script>
let scurveChart = null;
let chartDataLoaded = false;

// Called by switchMainTab() in project_detail.blade.php when Progress tab is clicked
function loadProgressChartData() {
    if (chartDataLoaded) return; // Only load once per page visit

    // Show loading spinner inside chart container
    const chartContainer = document.getElementById('scurveChartContainer');
    if (chartContainer) {
        chartContainer.innerHTML = `
            <div style="display:flex; flex-direction:column; align-items:center;
                        justify-content:center; height:300px; gap:12px; color:#6b7280;">
                <div class="spinner-border text-primary" role="status" style="width:2rem;height:2rem;"></div>
                <span style="font-size:13px;">Loading chart data...</span>
            </div>`;
    }

    fetch(`/${window.userRole}/projects/${window.projectId}/chart-data`, {
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.csrfToken }
    })
    .then(res => res.json())
    .then(response => {
        if (!response.success) {
            showChartError('Failed to load chart data.');
            return;
        }

        chartDataLoaded = true;
        const charts = response.charts;

        if (!charts.ready) {
            if (chartContainer) {
                chartContainer.innerHTML = `
                    <div class="alert alert-warning" style="padding:15px;font-size:13px;border-radius:8px;">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>No Data:</strong> Add baseline schedule monthly targets to see the progress chart.
                    </div>`;
            }
            return;
        }

        // Restore canvas element
        if (chartContainer) {
            chartContainer.innerHTML = '<canvas id="progressSCurveChart"></canvas>';
        }

        // Merge polynomial forecast into charts for Chart 1
        charts.polynomial_forecast = response.polynomial_forecast || [];

        initSCurveChart(charts);
        buildMonthlySummaryTable(charts.monthly_summary ?? []);

        // Render variance + burndown trend charts from the same AJAX response
        const trends = response.trends ?? {};
        renderVarianceTrendChart(trends.variance ?? { labels: [], data: [] });
        renderBurndownChart(trends.burndown ?? { labels: [], planned: [], completed: [] });

        // Initialize completion forecast chart (Chart 2) if shifted baseline data exists
        var completionForecast = response.completion_forecast || {};
        if (completionForecast.schedule_aware_shifted || completionForecast.eac_time_shifted) {
            initCompletionForecastChart(completionForecast);
        }
    })
    .catch(() => showChartError('An error occurred loading the chart.'));
}

function showChartError(msg) {
    const chartContainer = document.getElementById('scurveChartContainer');
    if (chartContainer) {
        chartContainer.innerHTML = `
            <div class="alert alert-danger" style="padding:15px;font-size:13px;border-radius:8px;">
                <i class="bi bi-x-circle"></i> ${msg}
            </div>`;
    }
}

function initSCurveChart(charts) {
    if (scurveChart) {
        scurveChart.destroy();
        scurveChart = null;
    }

    scurveChart = createSCurveChart('progressSCurveChart', charts);

    // Update legend visibility
    var actualData       = charts.actual_data         || [];
    var hasActual        = actualData.length > 0;
    var hasAdjustedPlan  = charts.has_extension && charts.adjusted_planned_data
        && charts.adjusted_planned_data.length > 0;
    var hasPolyForecast  = charts.polynomial_forecast && charts.polynomial_forecast.length > 0;

    var legendActual      = document.getElementById('legendActual');
    var legendAdjustedPlan = document.getElementById('legendAdjustedPlan');
    var legendPolyForecast = document.getElementById('legendPolyForecast');

    if (legendActual)        legendActual.style.display        = hasActual       ? 'flex' : 'none';
    if (legendAdjustedPlan)  legendAdjustedPlan.style.display  = hasAdjustedPlan ? 'flex' : 'none';
    if (legendPolyForecast)  legendPolyForecast.style.display  = hasPolyForecast ? 'flex' : 'none';
}

let completionForecastChart = null;

// Initialize the Completion Forecast S-Curve (Chart 2)
// Called from loadProgressChartData() when completion_forecast has shifted baseline data
function initCompletionForecastChart(data) {
    if (completionForecastChart) {
        completionForecastChart.destroy();
        completionForecastChart = null;
    }

    var container = document.getElementById('completionForecastChartContainer');
    if (!container) return;

    // Replace placeholder with canvas
    container.innerHTML = '<canvas id="completionForecastChart"></canvas>';

    var chartData = {
        frozen_planned_data:    data.frozen_planned_data || [],
        adjusted_planned_data:  data.adjusted_planned_data || [],
        has_extension:          data.has_extension || false,
        schedule_aware_shifted: data.schedule_aware_shifted || null,
        eac_time_shifted:       data.eac_time_shifted || null,
        schedule_aware_label:   data.schedule_aware_label || 'Schedule-Aware Forecast',
        eac_time_label:         data.eac_time_label || 'EAC_time Forecast',
        y_max:                  data.y_max || 100
    };

    completionForecastChart = createCompletionForecastChart('completionForecastChart', chartData);

    // Show the section card
    var section = document.getElementById('completionForecastSection');
    if (section) section.style.display = 'block';

    // Update legend visibility
    var legendAdjPlan = document.getElementById('legendAdjPlanCF');
    var legendSA      = document.getElementById('legendSA');
    var legendEAC     = document.getElementById('legendEAC');
    var labelSA       = document.getElementById('legendSALabel');
    var labelEAC      = document.getElementById('legendEACLabel');

    if (legendAdjPlan && data.has_extension && data.adjusted_planned_data && data.adjusted_planned_data.length > 0) {
        legendAdjPlan.style.display = 'flex';
    }
    if (legendSA && data.schedule_aware_shifted) {
        legendSA.style.display = 'flex';
        if (labelSA) labelSA.textContent = data.schedule_aware_label;
    }
    if (legendEAC && data.eac_time_shifted) {
        legendEAC.style.display = 'flex';
        if (labelEAC) labelEAC.textContent = data.eac_time_label;
    }
}

function buildMonthlySummaryTable(monthlySummary) {
    if (!monthlySummary || monthlySummary.length === 0) return;

    const tbody = document.getElementById('monthlySummaryBody');
    const wrapper = document.getElementById('monthlySummaryWrapper');
    if (!tbody || !wrapper) return;

    const statusColors = {
        'On Track': { bg: 'rgba(16,185,129,0.1)', color: '#10b981' },
        'Ahead':    { bg: 'rgba(34,197,94,0.1)',  color: '#22c55e' },
        'Behind':   { bg: 'rgba(249,115,22,0.1)', color: '#f97316' },
    };

    tbody.innerHTML = monthlySummary.map(month => {
        const variance      = parseFloat(month.variance) || 0;
        const varianceStr   = (variance >= 0 ? '+' : '') + variance.toFixed(1) + '%';
        const varianceColor = variance >= 0 ? '#10b981' : '#ef4444';
        const sc            = statusColors[month.status] ?? { bg: 'rgba(107,114,128,0.1)', color: '#6b7280' };

        return `<tr style="border-bottom:1px solid #e5e7eb;">
            <td style="padding:10px 12px;font-weight:500;">${month.month_display}</td>
            <td style="padding:10px 12px;text-align:center;color:#3b82f6;font-weight:600;">${parseFloat(month.planned_percent).toFixed(1)}%</td>
            <td style="padding:10px 12px;text-align:center;color:#10b981;font-weight:600;">${parseFloat(month.actual_percent).toFixed(1)}%</td>
            <td style="padding:10px 12px;text-align:center;font-weight:600;color:${varianceColor};">${varianceStr}</td>
            <td style="padding:10px 12px;text-align:center;">
                <span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;background:${sc.bg};color:${sc.color};">
                    ${month.status}
                </span>
            </td>
            <td style="padding:10px 12px;text-align:center;color:#6b7280;">${month.activities_completed}/${month.activities_total}</td>
        </tr>`;
    }).join('');

    wrapper.style.display = 'block';
}
</script>

<!-- ==================== VARIANCE TREND CHART (NEW) ==================== -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span class="card-title">
            <i class="bi bi-graph-up-arrow" style="color: var(--primary);"></i> Variance Trend (Monthly)
        </span>
        <span style="font-size: 12px; color: var(--text-muted);">
            <i class="bi bi-info-circle"></i> Project getting behind or ahead?
        </span>
    </div>
    <div style="padding: 20px;">
        <div style="position: relative; height: 280px; margin-bottom: 20px;">
            <canvas id="varianceTrendChart"></canvas>
        </div>
        <div style="display: flex; justify-content: center; gap: 30px; margin-bottom: 15px; flex-wrap: wrap; font-size: 12px;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #10b981; border-radius: 2px;"></div>
                <span>Ahead of Schedule (Positive)</span>
            </div>
            <div style="display: flex; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #f59e0b; border-radius: 2px;"></div>
                <span>On Schedule</span>
            </div>
            <div style="display: flex; align-items: center; gap: 6px;">
                <div style="width: 20px; height: 3px; background: #ef4444; border-radius: 2px;"></div>
                <span>Behind Schedule (Negative)</span>
            </div>
        </div>
    </div>
</div>

<!-- ==================== ACTIVITY BURNDOWN CHART (NEW) ==================== -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span class="card-title">
            <i class="bi bi-fire" style="color: var(--primary);"></i> Activity Burndown
        </span>
        <span style="font-size: 12px; color: var(--text-muted);">
            <i class="bi bi-info-circle"></i> Planned vs Completed activities per month
        </span>
    </div>
    <div style="padding: 20px;">
        <div style="position: relative; height: 280px; margin-bottom: 20px;">
            <canvas id="activityBurndownChart"></canvas>
        </div>
        <div style="display: flex; justify-content: center; gap: 30px; margin-bottom: 15px; flex-wrap: wrap; font-size: 12px;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <div style="width: 14px; height: 14px; background: rgba(59, 130, 246, 0.7); border-radius: 2px;"></div>
                <span>Planned Activities</span>
            </div>
            <div style="display: flex; align-items: center; gap: 6px;">
                <div style="width: 14px; height: 14px; background: rgba(16, 185, 129, 0.7); border-radius: 2px;"></div>
                <span>Completed Activities</span>
            </div>
        </div>
    </div>
</div>

<!-- ==================== RISK FLAGS DASHBOARD (NEW) ==================== -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white;">
        <h3 style="margin: 0; font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
            <i class="bi bi-shield-exclamation" style="font-size: 20px;"></i> Risk Flags & Alerts
        </h3>
    </div>
    <div style="padding: 20px;">
        <!-- RED FLAGS -->
        <div style="margin-bottom: 20px;">
            <div style="font-size: 12px; font-weight: 700; color: #dc2626; text-transform: uppercase; margin-bottom: 12px;">
                <i class="bi bi-exclamation-circle-fill" style="margin-right: 6px;"></i> Critical Issues
            </div>
            <div style="display: grid; gap: 10px;">
                <div style="padding: 12px 15px; background: rgba(220, 38, 38, 0.05); border-left: 4px solid #dc2626; border-radius: 4px;">
                    <div style="font-weight: 600; color: #111827; font-size: 13px;">📊 Variance Behind for 2+ Consecutive Months</div>
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">February & March both behind schedule (Avg -3%)</div>
                    <div style="font-size: 11px; color: #999; margin-top: 6px;">🔴 Action Required: Review scope and resource allocation</div>
                </div>
                <div style="padding: 12px 15px; background: rgba(220, 38, 38, 0.05); border-left: 4px solid #dc2626; border-radius: 4px;">
                    <div style="font-weight: 600; color: #111827; font-size: 13px;">📅 Projected Deadline Miss</div>
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">At current pace, project will finish ~45 days late</div>
                    <div style="font-size: 11px; color: #999; margin-top: 6px;">🔴 Action Required: Accelerate execution or extend deadline</div>
                </div>
            </div>
        </div>
        
        <!-- YELLOW FLAGS -->
        <div>
            <div style="font-size: 12px; font-weight: 700; color: #f59e0b; text-transform: uppercase; margin-bottom: 12px;">
                <i class="bi bi-exclamation-triangle-fill" style="margin-right: 6px;"></i> Warnings
            </div>
            <div style="display: grid; gap: 10px;">
                <div style="padding: 12px 15px; background: rgba(245, 158, 11, 0.05); border-left: 4px solid #f59e0b; border-radius: 4px;">
                    <div style="font-weight: 600; color: #111827; font-size: 13px;">📉 Variance Trending Negative</div>
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">Gap widening: Jan -1% → Feb -2% → Mar -3%</div>
                    <div style="font-size: 11px; color: #999; margin-top: 6px;">🟡 Monitor: Check for systemic issues</div>
                </div>
                <div style="padding: 12px 15px; background: rgba(245, 158, 11, 0.05); border-left: 4px solid #f59e0b; border-radius: 4px;">
                    <div style="font-weight: 600; color: #111827; font-size: 13px;">🎯 Critical Phase Approaching</div>
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">Next baseline month (April) requires 25% progress (high target)</div>
                    <div style="font-size: 11px; color: #999; margin-top: 6px;">🟡 Monitor: Prepare resources in advance</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- === RECORD MONTHLY PROGRESS BUTTON AND MODAL (Activity Checklist Model) === 
<div style="margin-top: 24px; display: flex; justify-content: center;">
    <button type="button" class="btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; padding: 12px 24px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#recordMonthlyProgressModal">
        <i class="bi bi-plus-circle-fill"></i>
        Record Monthly Progress (Activity Checklist)
    </button>
</div>
--> 

</div>
<!-- END PROGRESS TAB CONTENT -->

<!-- Include Edit Progress Modal -->
@include('projects.project_detail.components.modals._edit_progress_modal')

<script>
window._progressRecordData = @json($project->progressHistory->load('recordedBy')->load('attachments')->each->setAppends(['recorded_by_name', 'activity_names'])->keyBy('id'));
</script>

<script src="{{ asset('js/admin/projects/progress-attachments.js') }}"></script>
<script src="{{ asset('js/admin/projects/baseline-schedule-edit-progress-modal.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ==================== TREND CHART RENDERERS ====================
// Called from loadProgressChartData() after AJAX response — not on page load.

function renderVarianceTrendChart(varianceTrendData) {
    const varianceCtx = document.getElementById('varianceTrendChart');
    if (varianceCtx) {
        
        new Chart(varianceCtx, {
            type: 'line',
            data: {
                labels: varianceTrendData.labels || ['January', 'February', 'March', 'April', 'May'],
                datasets: [
                    {
                        label: 'Monthly Variance %',
                        data: varianceTrendData.data && varianceTrendData.data.length > 0 
                            ? varianceTrendData.data 
                            : [-1, -2, -3, -2.5, -1.5],
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.05)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        pointBackgroundColor: '#ef4444',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    },
                    {
                        label: 'Zero Line (On Schedule)',
                        data: (varianceTrendData.labels || []).map(() => 0),
                        borderColor: '#f59e0b',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        fill: false,
                        pointRadius: 0,
                        tension: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 10, right: 20, bottom: 10, left: 10 } },
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.85)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                if (context.dataset.label === 'Zero Line (On Schedule)') return null;
                                return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        },
                        grid: { color: 'rgba(0, 0, 0, 0.05)' }
                    }
                }
            }
        });
    }
}

function renderBurndownChart(burndownData) {
    // ==================== ACTIVITY BURNDOWN CHART ====================
    const burndownCtx = document.getElementById('activityBurndownChart');
    if (burndownCtx) {
        
        new Chart(burndownCtx, {
            type: 'bar',
            data: {
                labels: burndownData.labels || ['January', 'February', 'March', 'April', 'May'],
                datasets: [
                    {
                        label: 'Planned Activities',
                        data: burndownData.planned && burndownData.planned.length > 0 
                            ? burndownData.planned 
                            : [5, 6, 5, 7, 6],
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        borderRadius: 6
                    },
                    {
                        label: 'Completed Activities',
                        data: burndownData.completed && burndownData.completed.length > 0 
                            ? burndownData.completed 
                            : [5, 5, 4, 0, 0],
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: '#10b981',
                        borderWidth: 1,
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 10, right: 20, bottom: 10, left: 10 } },
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.85)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y + ' activities';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: false,
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        grid: { color: 'rgba(0, 0, 0, 0.05)' }
                    }
                }
            }
        });
    }
}
</script>
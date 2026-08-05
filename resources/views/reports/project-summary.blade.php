<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Project Summary — {{ $project->code }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11px;
            color: #1e293b;
            margin: 0;
            padding: 0;
            line-height: 1.45;
        }
        .header {
            border-bottom: 3px solid #2563eb;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        .header .eyebrow {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #2563eb;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .header h1 {
            font-size: 20px;
            margin: 0 0 4px 0;
            color: #0f172a;
        }
        .header .code {
            font-size: 12px;
            color: #475569;
        }
        .meta-grid {
            width: 100%;
            margin: 12px 0 18px 0;
            border-collapse: collapse;
        }
        .meta-grid td {
            width: 33.33%;
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            vertical-align: top;
        }
        .meta-grid .label {
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
            font-weight: 700;
            display: block;
            margin-bottom: 2px;
        }
        .meta-grid .value {
            font-size: 12px;
            font-weight: 600;
            color: #0f172a;
        }
        .meta-grid .value.peso {
            font-family: 'DejaVu Sans', 'Helvetica', 'Arial', sans-serif;
            font-weight: bold;
        }
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 4px;
            margin: 20px 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        table.data th {
            background: #2563eb;
            color: #ffffff;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 5px 6px;
            text-align: left;
        }
        table.data td {
            border-bottom: 1px solid #e2e8f0;
            padding: 5px 6px;
            font-size: 10.5px;
        }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            color: #ffffff;
            background: #64748b;
        }
        .status-Ongoing, .status-In-Progress, .status-Active { background: #2563eb; }
        .status-Completed { background: #16a34a; }
        .status-Planned { background: #f59e0b; }
        .status-On-Hold { background: #f43f5e; }
        .status-Cancelled, .status-Terminated { background: #ef4444; }
        .chart-box {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
            background: #ffffff;
            margin-bottom: 14px;
            text-align: center;
        }
        .footer {
            margin-top: 22px;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            font-size: 9px;
            color: #94a3b8;
            text-align: center;
        }
        .no-chart { color: #94a3b8; font-size: 11px; padding: 20px 0; }
        .ok { color: #16a34a; font-weight: 700; }
        .warn { color: #f59e0b; font-weight: 700; }
        .bad { color: #ef4444; font-weight: 700; }
    </style>
</head>
<body>

    <!-- HEADER -->
    <div class="header">
        <div class="eyebrow">CSPC PMU — Project Monitoring Report</div>
        <h1>{{ $project->title }}</h1>
        <div class="code">{{ $project->code }} • Generated {{ $generatedAt }}</div>
    </div>

    <!-- META GRID -->
    <table class="meta-grid">
        <tr>
            <td><span class="label">Status</span><span class="value">{{ $project->status }}</span></td>
            <td><span class="label">Category</span><span class="value">{{ $project->category?->name ?? '—' }}</span></td>
            <td><span class="label">Location</span><span class="value">{{ $project->location ?? '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Overall Progress</span><span class="value">{{ number_format($data['overview']['progress'] ?? 0, 1) }}%</span></td>
            <td><span class="label">Approved Budget</span><span class="value peso">₱ {{ number_format($project->approved_budget ?? 0, 2) }}</span></td>
            <td><span class="label">Contractor</span><span class="value">{{ $project->contractor?->company_name ?? '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Start Date</span><span class="value">{{ $project->planned_start_date?->format('M d, Y') ?? '—' }}</span></td>
            <td><span class="label">Planned End Date</span><span class="value">{{ $project->planned_end_date?->format('M d, Y') ?? '—' }}</span></td>
            <td><span class="label">Extensions</span><span class="value">{{ $project->extensions()->count() }}</span></td>
        </tr>
    </table>

    <!-- S-CURVE CHART -->
    <div class="section-title">Project Progress S-Curve</div>
    <div class="chart-box">
        {!! $svgChart !!}
    </div>

    <!-- LATEST MONTHLY STATUS -->
    @php($latest = $project->progressHistory()->reorder()->orderByDesc('inspection_date')->orderByDesc('created_at')->first())
    @if($latest)
        <div class="section-title">Latest Monthly Inspection</div>
        <table class="data">
            <tr>
                <th>Month</th>
                <th>Inspection Date</th>
                <th>Planned %</th>
                <th>Actual %</th>
                <th>Variance</th>
                <th>Remarks</th>
            </tr>
            <tr>
                <td>{{ $latest->month_label }}</td>
                <td>{{ $latest->inspection_date?->format('Y-m-d') }}</td>
                <td>{{ $latest->planned_progress_percent }}%</td>
                <td>{{ $latest->actual_progress_percent }}%</td>
                <td>
                    @php($variance = $latest->actual_progress_percent - $latest->planned_progress_percent)
                    <span class="{{ $variance < 0 ? 'bad' : ($variance === 0 ? 'ok' : 'warn') }}">{{ number_format($variance, 2) }}%</span>
                </td>
                <td>{{ $latest->remarks ?? '—' }}</td>
            </tr>
        </table>
    @endif

    <!-- OPEN ISSUES -->
    @php($openIssues = $project->issues()->whereIn('status', ['Open', 'In Progress'])->get())
    <div class="section-title">Open Issues ({{ $openIssues->count() }})</div>
    @if($openIssues->isEmpty())
        <p>No open issues.</p>
    @else
        <table class="data">
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Severity</th>
                <th>Status</th>
            </tr>
            @foreach($openIssues as $issue)
                <tr>
                    <td>{{ $issue->issue_name }}</td>
                    <td>{{ $issue->issue_type ?? '—' }}</td>
                    <td>{{ $issue->severity ?? '—' }}</td>
                    <td><span class="status-pill">{{ $issue->status }}</span></td>
                </tr>
            @endforeach
        </table>
    @endif

    <!-- EXTENSIONS -->
    @php($extensions = $project->extensions()->get())
    <div class="section-title">Extension History ({{ $extensions->count() }})</div>
    @if($extensions->isEmpty())
        <p>No extensions recorded.</p>
    @else
        <table class="data">
            <tr>
                <th>#</th>
                <th>Original End</th>
                <th>New End</th>
                <th>Days</th>
                <th>Status</th>
                <th>Reason</th>
            </tr>
            @foreach($extensions as $extension)
                <tr>
                    <td>{{ $extension->extension_number }}</td>
                    <td>{{ $extension->original_end_date?->format('Y-m-d') }}</td>
                    <td>{{ $extension->new_end_date?->format('Y-m-d') }}</td>
                    <td>{{ $extension->duration_days }}</td>
                    <td><span class="status-pill">{{ ucfirst($extension->status ?? '') }}</span></td>
                    <td>{{ $extension->reason }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="footer">
        Generated by CSPC PMU Project Monitoring System • {{ $generatedAt }}
    </div>

</body>
</html>

{{--
    Project Monitoring Report — PDF layout (dompdf).

    Renders the SAME report array as the Word file
    (App\Services\Reports\ProjectReportDataService::build), so the two formats
    never disagree; only the look differs. dompdf-safe CSS only: tables, no
    flexbox. The S-curve is the shared vector chart as a data-URI <img>
    ($chartImg, ScurveChartRenderer::svgImgTag). Page numbers are drawn by
    ReportExportService after render (dompdf page_text).

    Plan: textfiles/project_report_overhaul_plan.md
--}}
@use('App\Services\Reports\ReportFormat', 'F')
@php
    $m = $report['meta'];
    $s = $report['status'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $m['title'] }} — {{ $m['project_code'] }} — {{ $m['period_label'] }}</title>
    <style>
        @page { margin: 60px 48px 60px 48px; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1e293b; line-height: 1.45; margin: 0; }
        .office { font-size: 7.5px; font-weight: bold; letter-spacing: 1px; color: #1d4ed8; text-transform: uppercase; }
        h1 { font-size: 20px; margin: 4px 0 2px 0; color: #0f172a; }
        .project { font-size: 13px; font-weight: bold; color: #0f172a; margin-bottom: 4px; }
        .period { color: #64748b; margin-bottom: 4px; }
        .period b { color: #0f172a; }
        .rule { border-bottom: 2px solid #1d4ed8; margin: 6px 0 12px 0; }
        h2 { font-size: 11.5px; color: #1d4ed8; margin: 16px 0 6px 0; page-break-after: avoid; }
        h3 { font-size: 10px; color: #0f172a; margin: 10px 0 4px 0; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; vertical-align: top; text-align: left; }
        th { background: #e2e8f0; font-size: 8.5px; color: #1e293b; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        td.label { background: #f1f5f9; font-weight: bold; font-size: 8.5px; color: #334155; width: 34%; }
        .glance td { width: 25%; }
        .glance .k { background: #f1f5f9; font-weight: bold; font-size: 7.5px; color: #334155; }
        .glance .v { font-weight: bold; font-size: 12px; }
        .num { text-align: right; }
        .sub { display: block; font-size: 8px; color: #64748b; margin-top: 2px; }
        .muted { font-size: 8px; font-style: italic; color: #64748b; margin: 3px 0 6px 0; }
        .warn { color: #b45309; font-style: italic; }
        .chart { text-align: center; margin: 4px 0; }
        .no-chart { color: #64748b; font-style: italic; }
        ul, ol { margin: 2px 0 6px 0; padding-left: 16px; }
        li { margin-bottom: 2px; }
        p { margin: 0 0 6px 0; text-align: justify; }
        .closing { border-top: 1px solid #cbd5e1; margin-top: 18px; padding-top: 6px; font-size: 8px; font-style: italic; color: #64748b; }
    </style>
</head>
<body>

    {{-- Title block --}}
    <div class="office">{{ $m['office'] }}</div>
    <h1>{{ $m['title'] }}</h1>
    <div class="project">{{ $m['project_title'] }}</div>
    <div class="period">
        Reporting period: <b>{{ $m['period_label'] }}</b> &nbsp;·&nbsp;
        As of: <b>{{ $m['as_of'] }}</b> &nbsp;·&nbsp;
        Project code: <b>{{ $m['project_code'] }}</b>
    </div>
    @unless($m['has_inspection'])
        <div class="warn">No inspection has been recorded for this period yet.</div>
    @endunless
    <div class="rule"></div>

    {{-- 1. Project information --}}
    <h2>1. Project Information</h2>
    <table>
        @foreach($report['project'] as $row)
            <tr><td class="label">{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>
        @endforeach
    </table>

    {{-- 2. Status at a glance --}}
    <h2>2. Status at a Glance</h2>
    <table class="glance">
        <tr>
            <td class="k">Planned Progress</td><td class="k">Actual Progress</td><td class="k">Slippage</td><td class="k">Schedule Status</td>
        </tr>
        <tr>
            <td class="v">{{ F::pct($s['planned']) }}</td>
            <td class="v">{{ F::pct($s['actual']) }}</td>
            <td class="v" style="color:#{{ F::slippageColor($s['slippage']) }}">{{ F::signedPct($s['slippage']) }}</td>
            <td class="v" style="color:#{{ F::levelColor($s['level']) }}">{{ $s['label'] }}</td>
        </tr>
        <tr>
            <td class="k">Time Elapsed</td><td class="k">Days Remaining</td><td class="k">Approved Extensions</td><td class="k">Open Issues</td>
        </tr>
        <tr>
            <td class="v">{{ F::pct($s['time_elapsed']) }}</td>
            <td class="v">{{ F::daysRemaining($s['days_remaining']) }}</td>
            <td class="v">{{ $s['extensions_count'] }}{{ $s['extension_days'] ? ' (' . $s['extension_days'] . ' days)' : '' }}</td>
            <td class="v">{{ $s['open_issues'] }}</td>
        </tr>
    </table>
    @if($s['time_elapsed'] !== null && $s['actual'] !== null)
        <div class="muted">Time elapsed compares the calendar days used so far with the whole contract period; progress should keep pace with it.</div>
    @endif

    {{-- 3. S-curve --}}
    <h2>3. Progress S-Curve</h2>
    <div class="chart">{!! $chartImg !!}</div>
    <div class="muted">Original Plan is the approved baseline; Adjusted Plan appears when an extension was approved; Actual Progress is from the monthly inspections.</div>

    {{-- 4. Monthly progress --}}
    <h2>4. Monthly Progress</h2>
    @if(empty($report['progress']))
        <div class="muted">No inspection has been recorded yet.</div>
    @else
        <table>
            <thead><tr><th>Month</th><th>Inspection Date</th><th class="num">Planned</th><th class="num">Actual</th><th class="num">Slippage</th><th>Remarks</th></tr></thead>
            @foreach($report['progress'] as $r)
                <tr>
                    <td>{{ $r['month'] }}</td>
                    <td>{{ F::text($r['inspection_date']) }}</td>
                    <td class="num">{{ F::pct($r['planned']) }}</td>
                    <td class="num">{{ F::pct($r['actual']) }}</td>
                    <td class="num" style="color:#{{ F::slippageColor($r['slippage']) }}">{{ F::signedPct($r['slippage']) }}</td>
                    <td>{{ $r['remarks'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- 5. Work accomplished --}}
    <h2>5. Work Accomplished — {{ $m['period_label'] }}</h2>
    @php($w = $report['work'])
    @if($w['total'] === 0)
        <div class="muted">No activities are scheduled for this month.</div>
    @else
        <p><b>{{ $w['done_count'] }} of {{ $w['total'] }} scheduled {{ $w['total'] === 1 ? 'activity' : 'activities' }} completed.</b></p>
        <table>
            <thead><tr><th>Activity</th><th style="width:22%">Status</th></tr></thead>
            @foreach($w['activities'] as $a)
                <tr>
                    <td><b>{{ $a['name'] }}</b>@if(!empty($a['description']))<span class="sub">{{ $a['description'] }}</span>@endif</td>
                    <td style="color:{{ $a['done'] ? '#16a34a' : '#b45309' }}">{{ $a['done'] ? 'Completed' : 'Not yet completed' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    @if(!empty($w['carried']))
        <h3>Carried over into this month</h3>
        <ul>
            @foreach($w['carried'] as $c)
                <li>{{ $c['name'] }} — from {{ $c['from'] }}@if($c['times'] > 1) (carried over {{ $c['times'] }} times)@endif</li>
            @endforeach
        </ul>
    @endif

    {{-- 6. Issues --}}
    <h2>6. Issues and Problems Encountered</h2>
    <h3>Open at the end of the period ({{ count($report['issues']['open']) }})</h3>
    @if(empty($report['issues']['open']))
        <div class="muted">No open issues.</div>
    @else
        <table>
            <thead><tr><th style="width:38%">Issue</th><th>Type</th><th>Severity</th><th>Reported</th><th>Status</th></tr></thead>
            @foreach($report['issues']['open'] as $i)
                <tr>
                    <td><b>{{ $i['name'] }}</b>@if($i['description'] !== '—')<span class="sub">{{ $i['description'] }}</span>@endif</td>
                    <td>{{ $i['type'] }}</td>
                    <td>{{ $i['severity'] }}</td>
                    <td>{{ F::text($i['reported']) }}</td>
                    <td>{{ $i['status'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    <h3>Resolved during the period ({{ count($report['issues']['resolved']) }})</h3>
    @if(empty($report['issues']['resolved']))
        <div class="muted">No issues were resolved this month.</div>
    @else
        <table>
            <thead><tr><th style="width:35%">Issue</th><th style="width:20%">Resolved On</th><th>Action Taken / Resolution</th></tr></thead>
            @foreach($report['issues']['resolved'] as $i)
                <tr><td><b>{{ $i['name'] }}</b></td><td>{{ F::text($i['resolved_on']) }}</td><td>{{ $i['resolution'] }}</td></tr>
            @endforeach
        </table>
    @endif

    {{-- 7. Extensions --}}
    <h2>7. Time Extensions</h2>
    @if(empty($report['extensions']))
        <div class="muted">No time extension has been requested.</div>
    @else
        <table>
            <thead><tr><th>No.</th><th class="num">Days</th><th style="width:36%">Reason</th><th>Filed</th><th>Status</th><th>New Completion</th></tr></thead>
            @foreach($report['extensions'] as $e)
                <tr>
                    <td>{{ $e['number'] }}</td>
                    <td class="num">{{ $e['days'] }}</td>
                    <td>{{ $e['reason'] }}@if($e['category'] !== '—')<span class="sub">{{ $e['category'] }}</span>@endif</td>
                    <td>{{ F::text($e['filed']) }}</td>
                    <td>{{ $e['status'] }}@if($e['decided'] && $e['decided'] !== '—')<span class="sub">on {{ $e['decided'] }}</span>@endif</td>
                    <td>{{ F::text($e['new_end']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- 8. Forecast --}}
    <h2>8. Forecast</h2>
    @if(!$report['forecast']['available'])
        <div class="muted">{{ $report['forecast']['note'] }}</div>
    @else
        <ul>
            @foreach($report['forecast']['lines'] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
        <div class="muted">Forecasts are estimates from the inspection records so far and change as new inspections are recorded.</div>
    @endif

    {{-- 9. Analysis --}}
    <h2>9. Analysis</h2>
    @if(!$report['analysis']['available'])
        <div class="muted">No analysis has been generated for this period.</div>
    @else
        <div class="muted">System-generated analysis dated {{ $report['analysis']['generated_at'] }}. Review it before acting on it.</div>
        @foreach($report['analysis']['sections'] as $sec)
            <h3>{{ $sec['title'] }}</h3>
            @foreach($sec['blocks'] as $block)
                @if($block['type'] === 'list')
                    @if($block['ordered'])
                        <ol>@foreach($block['items'] as $item)<li>{{ $item }}</li>@endforeach</ol>
                    @else
                        <ul>@foreach($block['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>
                    @endif
                @else
                    <p>{{ $block['text'] }}</p>
                @endif
            @endforeach
        @endforeach
    @endif

    {{-- 10. Recommendations --}}
    <h2>10. Recommendations</h2>
    @if(empty($report['recommendations']))
        <div class="muted">No recommendations for this period.</div>
    @else
        <table>
            <thead><tr><th style="width:9%">Priority</th><th style="width:14%">Category</th><th>Recommended Action</th><th style="width:13%">Source</th></tr></thead>
            @foreach($report['recommendations'] as $r)
                <tr>
                    <td style="text-align:center"><b>{{ F::text($r['priority']) }}</b></td>
                    <td>{{ $r['category'] }}</td>
                    <td>{{ $r['action'] }}@if($r['reason'] !== '')<span class="sub"><i>{{ $r['reason'] }}</i></span>@endif</td>
                    <td><span class="sub">{{ $r['source'] }}</span></td>
                </tr>
            @endforeach
        </table>
        <div class="muted">Priority 1 is the most urgent and 5 the least.</div>
    @endif

    {{-- Closing — system-generated, no signatories (R-D6) --}}
    <div class="closing">
        {{ $report['closing']['statement'] }}<br>
        {{ $report['closing']['generated'] }}
    </div>

</body>
</html>

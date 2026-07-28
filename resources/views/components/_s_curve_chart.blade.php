{{--
    Reusable S-Curve Chart Container

    Renders a chart container div with the standard sizing and placeholder
    styling used by the Progress tab. The actual chart is created by calling
    createSCurveChart(chartId + 'Canvas', chartData) from s-curve-chart.js.

    Usage:
        @include('components._s_curve_chart', [
            'chartId'    => 'uniqueChartId',
            'height'     => '300px',       // optional, default 300px
            'placeholder' => 'Chart preview',  // optional
        ])

        <script>
            var chart = createSCurveChart('uniqueChartIdCanvas', chartData);
        </script>

    Dependencies:
        s-curve-chart.js (loaded by baseline-schedule.blade.php)
        Chart.js (loaded by progress.blade.php or included globally)

    Parameters:
        $chartId    - Unique ID prefix for this chart instance (required)
        $height     - CSS height value (optional, default '300px')
        $placeholder - Placeholder text when chart is empty (optional)
--}}
<div id="{{ $chartId }}Container"
     style="position: relative; height: {{ $height ?? '300px' }}; width: 100%;">
    <div style="display:flex; align-items:center; justify-content:center;
                height:100%; color:#9ca3af; font-size:13px; gap:8px;
                background: #f9fafb; border-radius: 8px;">
        <i class="bi bi-graph-up" style="font-size:20px;"></i>
        <span>{{ $placeholder ?? 'Chart preview' }}</span>
    </div>
</div>

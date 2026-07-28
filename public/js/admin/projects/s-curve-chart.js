/**
 * s-curve-chart.js
 * Shared S-Curve chart functions for the CSPC PMU System.
 *
 * Provides reusable Chart.js chart creation and data-building utilities
 * used by both the Progress tab and the Set Up Baseline modal.
 *
 * The chart uses a linear X-axis with millisecond timestamps to support
 * plotting individual inspection records at their exact dates (multi-dot
 * actual line). Tick labels are formatted as month-year strings.
 *
 * Dependencies:
 *   Chart.js (loaded via CDN in progress.blade.php or globally)
 *
 * Functions defined:
 *   createSCurveChart(canvasId, chartData)
 *   createCompletionForecastChart(canvasId, chartData)
 *   buildSetupPreviewChartData(localMonths)
 */

/**
 * Format a millisecond timestamp to a month-year label.
 * @param {number} ts - Millisecond timestamp
 * @returns {string} Formatted month-year string (e.g. "Jan 2026")
 */
function formatTimestampMonth(ts) {
    var d = new Date(ts);
    var months = ['Jan','Feb','Mar','Apr','May','Jun',
                  'Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()] + ' ' + d.getFullYear();
}

/**
 * Create an S-Curve chart using Chart.js with a linear (timestamp-based) X-axis.
 * Shows the original (frozen) plan, actual progress, and (if the project has
 * extensions) the adjusted plan line using planned_progress_percent.
 *
 * All datasets use {x, y} data points where x is a millisecond timestamp.
 *
 * Called by: progress.blade.php (initSCurveChart wrapper), setup modal
 * Calls: Chart.js constructor
 *
 * @param {string} canvasId - ID of the <canvas> element to render into
 * @param {Object} chartData - Chart data object:
 *   - labels: string[] - Month/period labels (for reference / fallback)
 *   - tick_timestamps: number[] - Millisecond timestamps for tick anchor points
 *   - frozen_planned_data: {x:number, y:number|null}[] - Original plan (frozen)
 *   - adjusted_planned_data: {x:number, y:number}[] - Current adjusted plan
 *   - actual_data: {x:number, y:number, month_label?:string}[] - All records
 *   - has_extension: boolean - Whether project has approved extensions
 *   - y_max: number - Y-axis maximum (may exceed 100 for extension months)
 *   - model: string - Chart model identifier
 * @returns {Chart|null} The Chart.js instance, or null if canvas/Chart.js not found
 */
function createSCurveChart(canvasId, chartData) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    if (typeof Chart === 'undefined') {
        console.error('createSCurveChart: Chart.js is not loaded.');
        return null;
    }

    var chartLabels          = chartData.labels                || [];
    var tickTimestamps       = chartData.tick_timestamps       || [];
    var frozenPlannedData    = chartData.frozen_planned_data   || [];
    var adjustedPlannedData  = chartData.adjusted_planned_data || [];
    var actualData           = chartData.actual_data           || [];
    var hasExtension         = chartData.has_extension         || false;
    var yMax                 = chartData.y_max                 || 100;

    var datasets = [];

    // Original Plan (frozen) — always present
    datasets.push({
        label: 'Original Plan',
        data: frozenPlannedData,
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59,130,246,0.05)',
        borderWidth: 2,
        tension: 0.3,
        fill: false,
        pointRadius: 5,
        pointHoverRadius: 7,
        pointBackgroundColor: '#3b82f6',
        pointBorderColor: '#1e40af',
        pointBorderWidth: 1,
        spanGaps: false,
        order: 1
    });

    // Actual Progress — show ALL individual inspection records
    var hasActualData = actualData && actualData.length > 0;

    if (hasActualData) {
        datasets.push({
            label: 'Actual Progress',
            data: actualData,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16,185,129,0.05)',
            borderWidth: 2,
            tension: 0.3,
            fill: false,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#10b981',
            pointBorderColor: '#047857',
            pointBorderWidth: 1,
            spanGaps: false,
            order: 2
        });
    }

    // Adjusted Plan (only if project has extensions)
    if (hasExtension) {
        datasets.push({
            label: 'Adjusted Plan',
            data: adjustedPlannedData,
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245,158,11,0.05)',
            borderWidth: 2,
            tension: 0.3,
            fill: false,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#f59e0b',
            pointBorderColor: '#d97706',
            pointBorderWidth: 1,
            order: 3,
            spanGaps: true
        });
    }

    // Polynomial Near-Term Forecast (purple dashed, first 4 months)
    // Only added when regression method is polynomial and forecast data exists
    var polyForecast = chartData.polynomial_forecast || [];
    if (polyForecast.length > 0) {
        datasets.push({
            label: 'Near-Term Forecast (Polynomial)',
            data: polyForecast,
            borderColor: '#a855f7',
            backgroundColor: 'rgba(168,85,247,0.05)',
            borderWidth: 2,
            borderDash: [5, 5],
            tension: 0.3,
            fill: false,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#a855f7',
            pointBorderColor: '#7e22ce',
            pointBorderWidth: 1,
            spanGaps: false,
            order: 4
        });
    }

    return new Chart(ctx, {
        type: 'line',
        data: {
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: { top: 10, right: 20, bottom: 10, left: 10 }
            },
            interaction: {
                intersect: false,
                mode: 'nearest',
                axis: 'x'
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.85)',
                    padding: 12,
                    titleFont: { size: 12, weight: 'bold' },
                    bodyFont: { size: 11 },
                    callbacks: {
                        title: function(context) {
                            if (context.length > 0 && context[0].parsed.x) {
                                return formatTimestampMonth(context[0].parsed.x);
                            }
                            return '';
                        },
                        label: function(context) {
                            return context.dataset.label + ': ' +
                                context.parsed.y.toFixed(1) + '%';
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    title: {
                        display: true,
                        text: 'Month',
                        font: { size: 12, weight: 'bold' }
                    },
                    ticks: {
                        font: { size: 10 },
                        maxRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: 12,
                        callback: function(value) {
                            return formatTimestampMonth(value);
                        }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                y: {
                    beginAtZero: true,
                    max: yMax,
                    title: {
                        display: true,
                        text: 'Cumulative Progress (%)',
                        font: { size: 12, weight: 'bold' }
                    },
                    ticks: {
                        font: { size: 10 },
                        callback: function(value) {
                            if (value > 100) return '';
                            return value + '%';
                        }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                }
            }
        }
    });
}

/**
 * Build a chart-compatible data object from the setup modal's local months array.
 * Converts the in-memory months into the format expected by createSCurveChart(),
 * with actual_data set to an empty array (no actual progress exists during
 * initial baseline setup).
 *
 * Planned data is converted to {x, y} format with timestamps derived from
 * the month_label (first day of each month).
 *
 * Called by: baseline-schedule-setup-modal.js
 * Calls: nothing
 *
 * @param {Array} localMonths - Array of { month_label, planned_progress_percent, ... }
 * @returns {Object} Chart data object compatible with createSCurveChart
 */
/**
 * Create a Completion Forecast S-Curve chart using Chart.js.
 *
 * Renders the original planned baseline alongside shifted baselines for
 * Schedule-Aware and EAC_time completion estimates. Each shifted baseline
 * is the original planned curve displaced horizontally by the method's
 * estimated lag.
 *
 * Called by: progress.blade.php (initCompletionForecastChart wrapper)
 * Calls: Chart.js constructor, formatTimestampMonth()
 *
 * @param {string} canvasId - ID of the <canvas> element to render into
 * @param {Object} chartData - Chart data object:
 *   - frozen_planned_data: {x:number, y:number|null}[] - Original plan
 *   - adjusted_planned_data: {x:number, y:number|null}[] - Adjusted plan (extension)
 *   - has_extension: boolean - Whether the project has approved extensions
 *   - schedule_aware_shifted: {x:number, y:number|null}[]|null - SA shifted baseline
 *   - eac_time_shifted: {x:number, y:number|null}[]|null - EAC_time shifted baseline
 *   - schedule_aware_label: string - Display label for SA
 *   - eac_time_label: string - Display label for EAC_time
 *   - y_max: number - Y-axis maximum
 * @returns {Chart|null} The Chart.js instance, or null if canvas/Chart.js not found
 */
function createCompletionForecastChart(canvasId, chartData) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    if (typeof Chart === 'undefined') {
        console.error('createCompletionForecastChart: Chart.js is not loaded.');
        return null;
    }

    var frozenPlannedData    = chartData.frozen_planned_data    || [];
    var adjustedPlannedData  = chartData.adjusted_planned_data  || [];
    var hasExtension         = chartData.has_extension          || false;
    var scheduleAwareData    = chartData.schedule_aware_shifted || [];
    var eacTimeData          = chartData.eac_time_shifted       || [];
    var saLabel              = chartData.schedule_aware_label   || 'Schedule-Aware Forecast';
    var eacLabel             = chartData.eac_time_label         || 'EAC_time Forecast';
    var yMax                 = chartData.y_max                  || 100;

    var datasets = [];

    // Original Plan (frozen) — always present
    datasets.push({
        label: 'Original Plan',
        data: frozenPlannedData,
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59,130,246,0.05)',
        borderWidth: 2,
        tension: 0.3,
        fill: false,
        pointRadius: 5,
        pointHoverRadius: 7,
        pointBackgroundColor: '#3b82f6',
        pointBorderColor: '#1e40af',
        pointBorderWidth: 1,
        spanGaps: false,
        order: 1
    });

    // Adjusted Plan (orange solid) — shown when project has extensions
    // Represents the current effective baseline after approved schedule changes
    if (hasExtension && adjustedPlannedData.length > 0) {
        datasets.push({
            label: 'Adjusted Plan',
            data: adjustedPlannedData,
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245,158,11,0.05)',
            borderWidth: 2,
            tension: 0.3,
            fill: false,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#f59e0b',
            pointBorderColor: '#d97706',
            pointBorderWidth: 1,
            order: 2,
            spanGaps: true
        });
    }

    // Schedule-Aware Forecast (purple dashed)
    if (scheduleAwareData.length > 0) {
        datasets.push({
            label: saLabel,
            data: scheduleAwareData,
            borderColor: '#8b5cf6',
            backgroundColor: 'rgba(139,92,246,0.05)',
            borderWidth: 2,
            borderDash: [6, 3],
            tension: 0.3,
            fill: false,
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBackgroundColor: '#8b5cf6',
            pointBorderColor: '#6d28d9',
            pointBorderWidth: 1,
            order: 3
        });
    }

    // EAC_time Forecast (orange dotted)
    if (eacTimeData.length > 0) {
        datasets.push({
            label: eacLabel,
            data: eacTimeData,
            borderColor: '#f97316',
            backgroundColor: 'rgba(249,115,22,0.05)',
            borderWidth: 2,
            borderDash: [2, 4],
            tension: 0.3,
            fill: false,
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBackgroundColor: '#f97316',
            pointBorderColor: '#ea580c',
            pointBorderWidth: 1,
            order: 4
        });
    }

    // Need at least the plan baseline plus one forecast to be useful
    if (datasets.length < 2) return null;

    return new Chart(ctx, {
        type: 'line',
        data: {
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: { top: 10, right: 20, bottom: 10, left: 10 }
            },
            interaction: {
                intersect: false,
                mode: 'nearest',
                axis: 'x'
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.85)',
                    padding: 12,
                    titleFont: { size: 12, weight: 'bold' },
                    bodyFont: { size: 11 },
                    callbacks: {
                        title: function(context) {
                            if (context.length > 0 && context[0].parsed.x) {
                                return formatTimestampMonth(context[0].parsed.x);
                            }
                            return '';
                        },
                        label: function(context) {
                            return context.dataset.label + ': ' +
                                context.parsed.y.toFixed(1) + '%';
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    title: {
                        display: true,
                        text: 'Month',
                        font: { size: 12, weight: 'bold' }
                    },
                    ticks: {
                        font: { size: 10 },
                        maxRotation: 45,
                        autoSkip: true,
                        maxTicksLimit: 12,
                        callback: function(value) {
                            return formatTimestampMonth(value);
                        }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                y: {
                    beginAtZero: true,
                    max: yMax,
                    title: {
                        display: true,
                        text: 'Cumulative Progress (%)',
                        font: { size: 12, weight: 'bold' }
                    },
                    ticks: {
                        font: { size: 10 },
                        callback: function(value) {
                            if (value > 100) return '';
                            return value + '%';
                        }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                }
            }
        }
    });
}

function buildSetupPreviewChartData(localMonths) {
    if (!localMonths || localMonths.length === 0) {
        return {
            labels: [],
            tick_timestamps: [],
            frozen_planned_data: [],
            adjusted_planned_data: [],
            actual_data: [],
            has_extension: false,
            y_max: 100,
            model: 'baseline_schedule'
        };
    }

    var labels = localMonths.map(function(m) {
        return m.month_label;
    });

    var tickTimestamps = localMonths.map(function(m) {
        return new Date(m.month_label + '-01').getTime();
    });

    // Frozen line: only months that have an original_planned_progress_percent
    // (non-null). Extension months are excluded — the Original Plan line must
    // stop at the last original month, not extend into extension territory.
    var frozenData = [];
    for (var fi = 0; fi < localMonths.length; fi++) {
        var fm = localMonths[fi];
        if (fm.original_planned_progress_percent !== null && fm.original_planned_progress_percent !== undefined) {
            frozenData.push({
                x: new Date(fm.month_label + '-01').getTime(),
                y: parseFloat(fm.original_planned_progress_percent)
            });
        }
    }

    // Adjusted line: always uses planned_progress_percent for all months
    var adjustedData = localMonths.map(function(m) {
        return {
            x: new Date(m.month_label + '-01').getTime(),
            y: parseFloat(m.planned_progress_percent) || 0
        };
    });

    // If no months have an original value (initial setup mode), fall back to
    // using adjusted data as the primary "Plan" dataset so the chart isn't empty.
    var primaryPlannedData = frozenData.length > 0 ? frozenData : adjustedData.slice();

    // Determine if the chart should also show the Adjusted Plan line.
    // True when there are extension months (original is explicitly null)
    // OR when any month has been adjusted (original differs from planned).
    // In initial setup mode, original_planned_progress_percent is undefined
    // for all months, so both conditions are false — correct.
    var hasExtension = localMonths.some(function(m) {
        return m.original_planned_progress_percent === null;
    }) || localMonths.some(function(m) {
        return m.original_planned_progress_percent !== null
            && m.original_planned_progress_percent !== undefined
            && parseFloat(m.original_planned_progress_percent) !== parseFloat(m.planned_progress_percent);
    });

    return {
        labels: labels,
        tick_timestamps: tickTimestamps,
        frozen_planned_data: primaryPlannedData,
        adjusted_planned_data: adjustedData,
        actual_data: [],
        has_extension: hasExtension,
        y_max: 100,
        model: 'baseline_schedule'
    };
}

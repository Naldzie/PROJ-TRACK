<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warranties Overview - PMU System</title>
    <style>
        :root {
            --header-teal-1: #7fc9b7;
            --header-teal-2: #4fa38d;
            --header-navy: #123028;
            --border-color: #26313f;
            --border-light: #c7cfd6;
            --text-main: #1a1f26;
            --text-muted: #5b6570;
            --zebra: #f7f9fa;
        }

        * { box-sizing: border-box; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }

        body {
            font-family: 'Courier New', Courier, monospace;
            background-color: #e9edf0;
            background-image: radial-gradient(rgba(11,18,32,0.05) 1px, transparent 1px);
            background-size: 22px 22px;
            padding: 24px;
            margin: 0;
            color: var(--text-main);
            position: relative;
            overflow-x: hidden;
        }

        /* ---------- Decorative corner ribbons ---------- */
        .corner-decor {
            position: fixed;
            width: 280px;
            height: 280px;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
            filter: drop-shadow(0 10px 20px rgba(11,18,32,0.28));
        }
        .corner-decor.top-left { top: -76px; left: -76px; }
        .corner-decor.bottom-right { bottom: -76px; right: -76px; transform: rotate(180deg); }

        .corner-decor .glow {
            position: absolute;
            inset: -50px;
            background: radial-gradient(circle at 32% 32%, rgba(56,211,232,0.4), transparent 62%);
            z-index: 0;
        }

        .corner-decor .band {
            position: absolute;
            width: 480px;
            height: 42px;
            transform: rotate(-45deg);
            left: -90px;
        }
        .corner-decor .band::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(255,255,255,0.22), rgba(255,255,255,0) 40%, rgba(0,0,0,0.12) 100%);
        }
        .corner-decor .band.navy  { background: linear-gradient(90deg, #0b1220, #16233d); top: 46px;  z-index: 4; }
        .corner-decor .band.indigo { background: linear-gradient(90deg, #142a52, #1e3a72); top: 90px;  z-index: 3; }
        .corner-decor .band.blue  { background: linear-gradient(90deg, #1d4ed8, #2f63f0); top: 134px; z-index: 2; }
        .corner-decor .band.teal  { background: linear-gradient(90deg, #17a6c7, #38d3e8); top: 178px; z-index: 1; }

        .corner-decor .shimmer {
            position: absolute;
            top: 40px;
            left: -300px;
            width: 140px;
            height: 190px;
            background: linear-gradient(100deg, transparent, rgba(255,255,255,0.32), transparent);
            transform: rotate(-45deg);
            animation: shimmerSweep 7s ease-in-out infinite;
            z-index: 5;
        }

        @keyframes shimmerSweep {
            0%   { left: -300px; opacity: 0; }
            15%  { opacity: 1; }
            45%  { left: 260px; opacity: 0; }
            100% { left: 260px; opacity: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            .corner-decor .shimmer { animation: none; display: none; }
        }

        .preview-container {
            position: relative;
            z-index: 1;
            max-width: 1500px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid var(--border-color);
        }

        .preview-container::before {
            content: '';
            display: block;
            height: 4px;
            background: linear-gradient(90deg, #0b1220, #1e3a72, #1d4ed8, #38d3e8);
        }

        /* ---------- Header ---------- */
        .preview-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            padding: 14px 18px;
            background: #fff;
            color: var(--text-main);
            border-bottom: 1px solid var(--border-color);
        }

        .title-left { display: flex; align-items: center; gap: 12px; }

        .preview-title h1 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .preview-title .meta {
            display: block;
            font-weight: 400;
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .page-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 11px;
            color: var(--text-muted);
        }

        .page-indicator .count-text {
            letter-spacing: 0.02em;
            white-space: nowrap;
        }

        .page-dots { display: flex; gap: 6px; }
        .page-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--border-light);
            transition: background 0.25s ease, transform 0.25s ease;
        }
        .page-dot.is-active { background: var(--header-teal-2); transform: scale(1.3); }

        /* ---------- Table ---------- */
        .table-responsive { overflow-x: auto; }

        table {
            width: 100%;
            min-width: 1080px;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        th, td {
            border: 1px solid var(--border-color);
            padding: 8px 10px;
            vertical-align: middle;
            text-align: left;
        }

        thead th {
            background: #a7d8cb;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        thead tr:first-child th { font-size: 12px; }
        thead tr:last-child th { font-size: 11.5px; }

        .col-num { width: 40px; text-align: center; color: var(--text-muted); }

        tbody { transition: opacity 0.3s ease; }

        tbody tr { transition: background 0.15s ease; }
        tbody tr[data-page]:nth-of-type(even) { background: var(--zebra); }
        tbody tr.row-hidden { display: none; }

        .project-name { font-weight: 700; line-height: 1.35; }
        .project-year { font-weight: 400; color: var(--text-muted); font-size: 11.5px; margin-top: 2px; }

        .contractor-cell, .date-cell, .period-cell, .insp-cell { white-space: nowrap; }

        .date-cell, .period-cell, .insp-cell { text-align: center; }

        .remarks-cell {
            min-width: 220px;
            max-width: 300px;
            white-space: normal;
            font-size: 11.5px;
            line-height: 1.4;
            color: var(--text-muted);
        }

        .cell-empty { color: var(--border-light); text-align: center; }

        .empty-state {
            padding: 60px 24px;
            text-align: center;
            color: var(--text-muted);
        }

        @media print {
            body { background: #fff; padding: 0; }
            .corner-decor { display: none; }
            .preview-container { border: 1px solid #000; box-shadow: none; border-radius: 0; }
            thead th { background: #a7d8cb !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tbody tr.row-hidden { display: table-row !important; }
            .page-indicator { display: none; }
        }

        @media (max-width: 900px) {
            body { padding: 12px; }
            th, td { padding: 6px 8px; }
            .preview-title { padding: 14px; }
        }
    </style>
</head>
<body>
    <div class="corner-decor top-left" aria-hidden="true">
        <span class="band navy"></span>
        <span class="band indigo"></span>
        <span class="band blue"></span>
        <span class="band teal"></span>
        <span class="shimmer"></span>
    </div>
    <div class="corner-decor bottom-right" aria-hidden="true">
        <span class="band navy"></span>
        <span class="band indigo"></span>
        <span class="band blue"></span>
        <span class="band teal"></span>
        <span class="shimmer"></span>
    </div>

    @php
        $rows = $warranties->map(function ($w) {
            $contractorName = $w->contractor ? $w->contractor->company_name : 'N/A';
            $completionDate = $w->completion_date ? \Carbon\Carbon::parse($w->completion_date) : null;
            $warrantyEnd = $w->end_date ? \Carbon\Carbon::parse($w->end_date) : null;
            $academicYear = $completionDate ? $completionDate->year . '-' . ($completionDate->year + 1) : '';

            return [
                'project' => $w->project->title,
                'academic_year' => $academicYear,
                'contractor' => $contractorName,
                'completion_date' => $completionDate ? $completionDate->format('F j, Y') : null,
                'warranty_period' => $warrantyEnd ? $warrantyEnd->format('F j, Y') : null,
                'mid_year' => $w->mid_year_inspection ? \Carbon\Carbon::parse($w->mid_year_inspection)->format('F j, Y') : null,
                'final' => $w->final_inspection ? \Carbon\Carbon::parse($w->final_inspection)->format('F j, Y') : null,
                'remarks' => $w->remarks,
            ];
        });

        $perPage = 10;
        $totalPages = max(1, (int) ceil($rows->count() / $perPage));
    @endphp

    <div class="preview-container">
        <div class="preview-title">
            <div class="title-left">
                <div>
                    <h1>Warranties Overview</h1>
                    <span class="meta">{{ $warranties->count() }} records &middot; Generated {{ now()->format('F d, Y g:i A') }}</span>
                </div>
            </div>

            @if($totalPages > 1)
                <div class="page-indicator">
                    <span class="count-text" id="page-count-text">Showing 1&ndash;{{ min($perPage, $rows->count()) }} of {{ $rows->count() }}</span>
                    <div class="page-dots" id="page-dots">
                        @for($p = 1; $p <= $totalPages; $p++)
                            <span class="page-dot {{ $p === 1 ? 'is-active' : '' }}" data-dot="{{ $p }}"></span>
                        @endfor
                    </div>
                </div>
            @endif
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2"></th>
                        <th rowspan="2">Infrastructure Project</th>
                        <th rowspan="2">Contractor</th>
                        <th rowspan="2">Date of<br>Completion</th>
                        <th rowspan="2">Warranty<br>Period</th>
                        <th colspan="2">Inspection</th>
                        <th rowspan="2">Remarks</th>
                    </tr>
                    <tr>
                        <th>Mid-Year</th>
                        <th>Final</th>
                    </tr>
                </thead>
                <tbody id="warranty-tbody">
                    @forelse($rows as $r)
                        <tr data-page="{{ intdiv($loop->index, $perPage) + 1 }}" class="{{ $loop->index < $perPage ? '' : 'row-hidden' }}">
                            <td class="col-num">{{ $loop->iteration }}</td>
                            <td class="project-cell">
                                <div class="project-name">{{ $r['project'] }}</div>
                                @if($r['academic_year'])
                                    <div class="project-year">({{ $r['academic_year'] }})</div>
                                @endif
                            </td>
                            <td class="contractor-cell">{{ $r['contractor'] }}</td>
                            <td class="date-cell">{{ $r['completion_date'] ?? '—' }}</td>
                            <td class="period-cell">{{ $r['warranty_period'] ?? '—' }}</td>
                            <td class="insp-cell">
                                @if($r['mid_year'])
                                    {{ $r['mid_year'] }}
                                @else
                                    <span class="cell-empty">—</span>
                                @endif
                            </td>
                            <td class="insp-cell">
                                @if($r['final'])
                                    {{ $r['final'] }}
                                @else
                                    <span class="cell-empty">—</span>
                                @endif
                            </td>
                            <td class="remarks-cell">{{ $r['remarks'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">No warranty records yet.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        (function () {
            var perPage = {{ $perPage }};
            var totalPages = {{ $totalPages }};
            var totalRecords = {{ $rows->count() }};
            if (totalPages <= 1) return;

            var tbody = document.getElementById('warranty-tbody');
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-page]'));
            var countText = document.getElementById('page-count-text');
            var dots = Array.prototype.slice.call(document.querySelectorAll('.page-dot'));
            var currentPage = 1;

            function showPage(page) {
                tbody.style.opacity = 0;
                setTimeout(function () {
                    rows.forEach(function (row) {
                        row.classList.toggle('row-hidden', parseInt(row.dataset.page, 10) !== page);
                    });
                    var start = (page - 1) * perPage + 1;
                    var end = Math.min(page * perPage, totalRecords);
                    countText.textContent = 'Showing ' + start + '\u2013' + end + ' of ' + totalRecords;
                    dots.forEach(function (dot) {
                        dot.classList.toggle('is-active', parseInt(dot.dataset.dot, 10) === page);
                    });
                    tbody.style.opacity = 1;
                }, 250);
            }

            setInterval(function () {
                currentPage = currentPage >= totalPages ? 1 : currentPage + 1;
                showPage(currentPage);
            }, 30000);
        })();
    </script>
</body>
</html>
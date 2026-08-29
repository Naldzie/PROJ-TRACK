{{-- Shared notifications list (gaps #12). Rendered inside each role wrapper;
     expects $notifications (paginated) and $rolePrefix (admin|staff|contractor). --}}

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
    :root {
        --primary: #2563eb;
        --bg: linear-gradient(135deg, #eef2ff, #f8fafc);
        --card-bg: rgba(255, 255, 255, 0.9);
        --border: #e5e7eb;
        --text-main: #1f2937;
        --text-muted: #6b7280;
    }
    .notif-wrap { max-width: 860px; margin: auto; }
    .notif-page-title { font-weight: 800; font-size: 22px; color: var(--text-main); margin-bottom: 4px; }
    .notif-page-sub { color: var(--text-muted); font-size: 13px; margin-bottom: 20px; }
    .notif-list-card {
        background: var(--card-bg);
        border: 1px solid var(--border);
        border-radius: 18px;
        box-shadow: 0 12px 30px rgba(0,0,0,0.06);
        overflow: hidden;
    }
    .notif-row {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 15px 18px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .notif-row:last-child { border-bottom: none; }
    .notif-row.unread { background: #eff6ff; }
    .notif-icon {
        width: 38px; height: 38px; flex: none;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 17px;
        background: #dbeafe; color: #2563eb;
    }
    .notif-icon.red { background: #fee2e2; color: #dc2626; }
    .notif-icon.amber { background: #fef3c7; color: #d97706; }
    .notif-icon.violet { background: #ede9fe; color: #7c3aed; }
    .notif-icon.green { background: #d1fae5; color: #059669; }
    .notif-title { font-weight: 700; font-size: 14px; color: var(--text-main); display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
    .notif-meta { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
    .notif-badge { font-size: 11px; font-weight: 600; }
    .notif-dot { width: 8px; height: 8px; border-radius: 50%; background: #2563eb; display: inline-block; flex: none; }
    .notif-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; flex: none; }
    .notif-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .notif-unread-count { font-size: 13px; color: var(--text-muted); }

    @media (max-width: 576px) {
        .notif-row { flex-wrap: wrap; gap: 10px; padding: 12px 14px; }
        .notif-actions { margin-left: 48px; }
        .notif-toolbar { gap: 8px; flex-wrap: wrap; }
        .notif-page-title { font-size: 19px; }
    }
</style>

<div class="notif-wrap py-4">
    <div class="notif-page-title">Notifications</div>
    <div class="notif-page-sub">Deadline reminders, overdue inspections, extension updates, and issue alerts.</div>

    <div class="notif-toolbar">
        <span class="notif-unread-count">
            {{ auth()->user()->unreadNotifications()->count() }} unread
        </span>
        @if(auth()->user()->unreadNotifications()->count() > 0)
            <button class="btn btn-sm btn-primary" id="notifReadAll"
                    data-url="{{ route($rolePrefix . '.notifications.readAll') }}">
                Mark all read
            </button>
        @endif
    </div>

    <div class="notif-list-card">
        @forelse($notifications as $n)
            @php
                $d = $n->data;
                $isRiskLevel = str_contains($n->type, 'RiskLevelChanged');
                $isPlannedInsp = str_contains($n->type, 'PlannedInspectionReminder');
                $isMonthEnd = str_contains($n->type, 'MonthEndInspectionReminder');

                $cls = match(true) {
                    str_contains($n->type, 'DeadlineReminder')         => 'red',
                    str_contains($n->type, 'OverdueInspectionReminder') => 'amber',
                    $isRiskLevel                                       => 'red',
                    str_contains($n->type, 'ExtensionStatusChanged')  => 'violet',
                    $isPlannedInsp                                     => 'blue',
                    $isMonthEnd                                        => 'amber',
                    default                                            => 'green',
                };

                $icon = match(true) {
                    str_contains($n->type, 'DeadlineReminder')         => 'bi-clock',
                    str_contains($n->type, 'OverdueInspectionReminder') => 'bi-calendar-x',
                    $isRiskLevel                                       => 'bi-shield-exclamation',
                    str_contains($n->type, 'ExtensionStatusChanged')  => 'bi-arrow-repeat',
                    $isPlannedInsp                                     => 'bi-calendar-check',
                    $isMonthEnd                                        => 'bi-calendar-event',
                    default                                            => 'bi-exclamation-triangle',
                };

                $title = match(true) {
                    str_contains($n->type, 'DeadlineReminder') => ($d['bucket'] === 'overdue'
                        ? 'Project overdue — ' . ($d['project_title'] ?? '')
                        : 'Deadline in ' . ($d['days_remaining'] ?? 0) . ' days — ' . ($d['project_title'] ?? '')),
                    $isRiskLevel => 'Risk level changed — ' . ($d['project_title'] ?? '') . ' (' . ($d['previous_level'] ?? '') . ' → ' . ($d['current_level'] ?? '') . ')',
                    str_contains($n->type, 'OverdueInspectionReminder') => 'Inspection overdue — ' . ($d['project_title'] ?? '') . ' (' . ($d['month_display'] ?? $d['month_label'] ?? '') . ')',
                    $isPlannedInsp => 'Inspection scheduled today — ' . ($d['project_title'] ?? '') . ' (' . ($d['month_display'] ?? $d['month_label'] ?? '') . ')',
                    $isMonthEnd => 'Month ending soon — inspect ' . ($d['project_title'] ?? '') . ' (' . ($d['month_display'] ?? $d['month_label'] ?? '') . ')',
                    str_contains($n->type, 'ExtensionStatusChanged') => 'Extension ' . ucfirst($d['status'] ?? '') . ' #' . ($d['extension_number'] ?? '') . ' — ' . ($d['project_title'] ?? ''),
                    default => 'Issue reported' . (($d['severity'] ?? '') ? ' (' . $d['severity'] . ')' : '') . ' — ' . ($d['issue_name'] ?? '') . ' on ' . ($d['project_title'] ?? ''),
                };

                $nPid = $d['project_id'] ?? null;
                $nRoute = match($rolePrefix ?? 'admin') { 'staff' => 'staff.projects.detail', 'contractor' => 'projects.show', default => 'admin.projects.detail' };
                $nAnchor = match(true) {
                    str_contains($n->type, 'ExtensionStatusChanged')     => '#extensions',
                    str_contains($n->type, 'IssueReported')             => '#issues',
                    str_contains($n->type, 'RiskReported')              => '#risks',
                    str_contains($n->type, 'OverdueInspectionReminder') => '#baseline-schedule',
                    $isPlannedInsp                                      => '#baseline-schedule',
                    $isMonthEnd                                         => '#baseline-schedule',
                    default                                             => '#overview',
                };
                $nUrl = $nPid ? route($nRoute, $nPid) . $nAnchor : '';
            @endphp
            <div class="notif-row {{ $n->read_at ? '' : 'unread' }}"
                 data-navigate-url="{{ $nUrl }}">
                <div class="notif-icon {{ $cls }}"><i class="bi {{ $icon }}"></i></div>
                <div style="flex:1; min-width:0;">
                    <div class="notif-title">
                        {{ $title }}
                        @if(!$n->read_at)
                            <span class="notif-dot"></span>
                        @endif
                    </div>
                    <div class="notif-meta">{{ $n->created_at?->format('M d, Y h:i A') }}</div>
                    @if(str_contains($n->type, 'DeadlineReminder') && !empty($d['planned_end_date']))
                        @if(($d['bucket'] ?? '') === 'overdue')
                            <div class="notif-meta" style="margin-top:4px; color:#b45309;">Due: {{ \Illuminate\Support\Carbon::parse($d['planned_end_date'])->format('M d, Y') }} · Overdue by {{ abs($d['days_remaining'] ?? 0) }} day(s)</div>
                        @else
                            <div class="notif-meta" style="margin-top:4px; color:#374151;">Due: {{ \Illuminate\Support\Carbon::parse($d['planned_end_date'])->format('M d, Y') }} · {{ $d['days_remaining'] ?? 0 }} day(s) remaining</div>
                        @endif
                    @endif
                    @if(str_contains($n->type, 'OverdueInspectionReminder') && !empty($d['planned_date']))
                        <div class="notif-meta" style="margin-top:4px; color:#b45309;">Planned inspection: {{ \Illuminate\Support\Carbon::parse($d['planned_date'])->format('M d, Y') }}</div>
                    @endif
                    @if($isPlannedInsp && !empty($d['planned_date']))
                        <div class="notif-meta" style="margin-top:4px; color:#2563eb;">Planned inspection: {{ \Illuminate\Support\Carbon::parse($d['planned_date'])->format('M d, Y') }}</div>
                    @endif
                    @if($isMonthEnd && !empty($d['planned_date']))
                        <div class="notif-meta" style="margin-top:4px; color:#b45309;">Planned inspection: {{ \Illuminate\Support\Carbon::parse($d['planned_date'])->format('M d, Y') }}</div>
                    @endif
                    @if(str_contains($n->type, 'ExtensionStatusChanged') && ($d['status'] ?? '') === 'rejected' && !empty($d['rejection_reason']))
                        <div class="notif-meta" style="margin-top:4px; color:#b91c1c;">Reason: {{ $d['rejection_reason'] }}</div>
                    @endif
                    @if(str_contains($n->type, 'ExtensionStatusChanged') && ($d['status'] ?? '') === 'approved')
                        @if(!empty($d['current_end_date']))
                            <div class="notif-meta" style="margin-top:4px; color:#374151;">Current end: {{ $d['current_end_date'] }}</div>
                        @endif
                        @if(!empty($d['approved_end_date']))
                            <div class="notif-meta" style="margin-top:2px; color:#059669;">New end: {{ $d['approved_end_date'] }}</div>
                        @endif
                        @if(!empty($d['days_extended']))
                            <div class="notif-meta" style="margin-top:2px; color:#6b7280;">Extended by {{ $d['days_extended'] }} day(s)</div>
                        @endif
                    @endif
                    @if($isRiskLevel && isset($d['month_display']))
                        <div class="notif-meta" style="margin-top:4px; color:#374151;">
                            Planned: {{ number_format($d['planned_progress'] ?? 0, 1) }}% · Actual: {{ number_format($d['actual_progress'] ?? 0, 1) }}% · Variance: {{ number_format($d['variance'] ?? 0, 2) }}%
                        </div>
                    @endif
                </div>
                @if(!$n->read_at)
                    <div class="notif-actions">
                        <button class="btn btn-sm btn-outline-primary notif-read-one"
                                data-url="{{ route($rolePrefix . '.notifications.read', $n->id) }}"
                                data-navigate-url="{{ $nUrl }}">Mark read</button>
                    </div>
                @endif
            </div>
        @empty
            <div style="padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 14px;">
                <div style="font-size: 34px; margin-bottom: 8px;"><i class="bi bi-bell-slash"></i></div>
                You're all caught up — no notifications.
            </div>
        @endforelse
    </div>

    <div class="mt-3">
        {{ $notifications->links('pagination::bootstrap-5') }}
    </div>
</div>

<script>
    document.querySelectorAll('.notif-read-one').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            fetch(btn.dataset.url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            }).then(function () {
                var nav = btn.dataset.navigateUrl;
                if (nav) window.location.href = nav;
                else location.reload();
            });
        });
    });

    document.querySelectorAll('.notif-row').forEach(function (row) {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function () {
            var nav = row.dataset.navigateUrl;
            if (nav) window.location.href = nav;
        });
    });

    var readAllBtn = document.getElementById('notifReadAll');
    if (readAllBtn) {
        readAllBtn.addEventListener('click', function () {
            fetch(readAllBtn.dataset.url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            }).then(function () { location.reload(); });
        });
    }
</script>

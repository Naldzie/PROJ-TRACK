{{-- Shared notifications list (gaps #12). Rendered inside each role wrapper;
     expects $notifications (paginated) and $rolePrefix (admin|staff|contractor).
     2026-09-21 (restudy P-6): wording from App\Services\NotificationPresenter; filters
     All / Unread, by type and by project ($filters, $typeOptions, $projectOptions from
     NotificationController::index); "Clear read" hides read notices ($readCount). --}}

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

@php
    $notifPresenter = app(\App\Services\NotificationPresenter::class);
    $filters = $filters ?? ['status' => 'all', 'type' => null, 'project' => null];
    $typeOptions = $typeOptions ?? [];
    $projectOptions = $projectOptions ?? [];
    $readCount = $readCount ?? 0;
    $unreadTotal = auth()->user()->unreadNotifications()->count();
    $indexRoute = $rolePrefix . '.notifications';
    // Same filters with one value changed (keeps the others)
    $filterUrl = fn (array $change) => route($indexRoute, array_filter(array_merge([
        'status'  => $filters['status'] === 'unread' ? 'unread' : null,
        'type'    => $filters['type'],
        'project' => $filters['project'],
    ], $change), fn ($v) => $v !== null && $v !== ''));
    $isFiltered = $filters['status'] === 'unread' || $filters['type'] || $filters['project'];
@endphp

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
    .notif-type-chip { font-size: 11px; font-weight: 600; color: #475569; background: #f1f5f9; border-radius: 999px; padding: 1px 8px; }
    .notif-dot { width: 8px; height: 8px; border-radius: 50%; background: #2563eb; display: inline-block; flex: none; }
    .notif-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; flex: none; }
    .notif-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
    .notif-toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .notif-unread-count { font-size: 13px; color: var(--text-muted); }
    .notif-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
    .notif-tabs { display: inline-flex; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: #fff; }
    .notif-tabs a { padding: 6px 14px; font-size: 13px; font-weight: 600; color: var(--text-muted); text-decoration: none; }
    .notif-tabs a.active { background: var(--primary); color: #fff; }
    .notif-filters .form-select { width: auto; min-width: 170px; max-width: 280px; font-size: 13px; }
    .notif-clear-filters { font-size: 13px; }

    @media (max-width: 576px) {
        .notif-row { flex-wrap: wrap; gap: 10px; padding: 12px 14px; }
        .notif-actions { margin-left: 48px; }
        .notif-page-title { font-size: 19px; }
        .notif-filters .form-select { min-width: 0; max-width: none; flex: 1 1 100%; }
    }
</style>

<div class="notif-wrap py-4">
    <div class="notif-page-title">Notifications</div>
    <div class="notif-page-sub">Deadline reminders, overdue inspections, extension updates, issue alerts, and project monitoring notices.</div>

    <div class="notif-toolbar">
        <span class="notif-unread-count">{{ $unreadTotal }} unread</span>
        <div class="notif-toolbar-actions">
            @if($readCount > 0)
                <button class="btn btn-sm btn-outline-secondary" id="notifClearRead"
                        data-url="{{ route($rolePrefix . '.notifications.clearRead') }}"
                        data-count="{{ $readCount }}"
                        title="Hide all read notifications from this list">
                    <i class="bi bi-trash3"></i> Clear read ({{ $readCount }})
                </button>
            @endif
            @if($unreadTotal > 0)
                <button class="btn btn-sm btn-primary" id="notifReadAll"
                        data-url="{{ route($rolePrefix . '.notifications.readAll') }}">
                    Mark all read
                </button>
            @endif
        </div>
    </div>

    {{-- Filters: All / Unread tabs, type, project (plain GET links — no JS needed) --}}
    <div class="notif-filters">
        <div class="notif-tabs" role="tablist">
            <a href="{{ $filterUrl(['status' => null]) }}" class="{{ $filters['status'] === 'unread' ? '' : 'active' }}">All</a>
            <a href="{{ $filterUrl(['status' => 'unread']) }}" class="{{ $filters['status'] === 'unread' ? 'active' : '' }}">Unread</a>
        </div>
        @if(count($typeOptions) > 1 || $filters['type'])
            <select class="form-select form-select-sm notif-filter-select" aria-label="Filter by type">
                <option value="{{ $filterUrl(['type' => null]) }}">All types</option>
                @foreach($typeOptions as $slug => $label)
                    <option value="{{ $filterUrl(['type' => $slug]) }}" @selected($filters['type'] === $slug)>{{ $label }}</option>
                @endforeach
            </select>
        @endif
        @if(count($projectOptions) > 1 || $filters['project'])
            <select class="form-select form-select-sm notif-filter-select" aria-label="Filter by project">
                <option value="{{ $filterUrl(['project' => null]) }}">All projects</option>
                @foreach($projectOptions as $pid => $ptitle)
                    <option value="{{ $filterUrl(['project' => $pid]) }}" @selected((int) $filters['project'] === (int) $pid)>{{ \Illuminate\Support\Str::limit($ptitle, 60) }}</option>
                @endforeach
            </select>
        @endif
        @if($isFiltered)
            <a href="{{ route($indexRoute) }}" class="notif-clear-filters">Reset filters</a>
        @endif
    </div>

    <div class="notif-list-card">
        @forelse($notifications as $n)
            @php $p = $notifPresenter->present($n, $rolePrefix); @endphp
            <div class="notif-row {{ $n->read_at ? '' : 'unread' }}"
                 data-navigate-url="{{ $p['url'] }}">
                <div class="notif-icon {{ $p['tone'] }}"><i class="bi {{ $p['icon'] }}"></i></div>
                <div style="flex:1; min-width:0;">
                    <div class="notif-title">
                        {{ $p['title'] }}
                        @if(!$n->read_at)
                            <span class="notif-dot"></span>
                        @endif
                    </div>
                    <div class="notif-meta">
                        <span class="notif-type-chip">{{ $p['type'] }}</span>
                        {{ $p['created_at'] }} · {{ $p['created'] }}
                    </div>
                    @foreach($p['details'] as [$text, $color])
                        <div class="notif-meta" style="margin-top:{{ $loop->first ? 4 : 2 }}px; color:{{ $color }};">{{ $text }}</div>
                    @endforeach
                </div>
                @if(!$n->read_at)
                    <div class="notif-actions">
                        <button class="btn btn-sm btn-outline-primary notif-read-one"
                                data-url="{{ route($rolePrefix . '.notifications.read', $n->id) }}"
                                data-navigate-url="{{ $p['url'] }}">Mark read</button>
                    </div>
                @endif
            </div>
        @empty
            <div style="padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 14px;">
                <div style="font-size: 34px; margin-bottom: 8px;"><i class="bi bi-bell-slash"></i></div>
                @if($isFiltered)
                    No notifications match these filters.
                @else
                    You're all caught up — no notifications.
                @endif
            </div>
        @endforelse
    </div>

    <div class="mt-3">
        {{ $notifications->links('pagination::bootstrap-5') }}
    </div>
</div>

<script>
    // POST helper for read / read-all / clear-read
    function notifPost(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            }
        });
    }

    document.querySelectorAll('.notif-read-one').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            notifPost(btn.dataset.url).then(function () {
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

    // Type / project filter dropdowns: each option's value is the filtered URL
    document.querySelectorAll('.notif-filter-select').forEach(function (sel) {
        sel.addEventListener('change', function () { window.location.href = sel.value; });
    });

    var readAllBtn = document.getElementById('notifReadAll');
    if (readAllBtn) {
        readAllBtn.addEventListener('click', function () {
            notifPost(readAllBtn.dataset.url).then(function () { location.reload(); });
        });
    }

    // Clear read: confirm first — cleared notices no longer appear in this list
    var clearReadBtn = document.getElementById('notifClearRead');
    if (clearReadBtn) {
        clearReadBtn.addEventListener('click', function () {
            var n = clearReadBtn.dataset.count;
            if (!window.confirm('Clear ' + n + ' read notification' + (n === '1' ? '' : 's') + ' from this list? Unread notifications are kept.')) return;
            notifPost(clearReadBtn.dataset.url).then(function () { window.location.href = @json(route($indexRoute)); });
        });
    }
</script>

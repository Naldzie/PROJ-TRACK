{{-- In-app notification bell (gaps #12). Included in the .profile-section of all three
     role layouts. Server-rendered unread badge + latest 5 in a Bootstrap dropdown;
     mark-all-read posts to the role-scoped route. --}}
@php
    $notifUser = auth()->user();
    $unreadCount = $notifUser->unreadNotifications()->count();
    $recentNotifs = $notifUser->unreadNotifications()->latest()->take(5)->get();
    $rolePrefix = $notifUser->role; // admin | staff | contractor
@endphp

<div class="dropdown notification-dropdown">
    <button class="btn btn-link p-0 position-relative notification-bell"
            type="button" data-bs-toggle="dropdown" aria-expanded="false"
            title="Notifications">
        <i class="bi bi-bell" style="font-size: 20px; color: #fff;"></i>
        @if($unreadCount > 0)
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill notification-badge">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div class="dropdown-menu dropdown-menu-end notification-menu" style="width: 340px;">
        <div class="notification-menu-header">
            <span>Notifications</span>
            @if($unreadCount > 0)
                <button type="button" class="btn btn-link btn-sm p-0 notification-mark-all"
                        data-url="{{ route($rolePrefix . '.notifications.readAll') }}">
                    Mark all read
                </button>
            @endif
        </div>

        <div class="notification-menu-body">
            @forelse($recentNotifs as $n)
                @php $d = $n->data; @endphp
                <div class="notification-item {{ $n->read_at ? '' : 'unread' }}"
                     data-id="{{ $n->id }}"
                     data-url="{{ route($rolePrefix . '.notifications.read', $n->id) }}">
                    <div class="notification-item-title">
                        {{ $n->type === \App\Notifications\DeadlineReminder::class
                            ? ($d['bucket'] === 'overdue'
                                ? 'Project overdue'
                                : 'Deadline in ' . ($d['days_remaining'] ?? 0) . ' days')
                            : ($n->type === \App\Notifications\OverdueInspectionReminder::class
                                ? 'Inspection overdue'
                                : ($n->type === \App\Notifications\ExtensionStatusChanged::class
                                    ? 'Extension ' . ucfirst($d['status'] ?? '')
                                    : 'Issue reported')) }}
                        @if(!$n->read_at)
                            <span class="notification-dot"></span>
                        @endif
                    </div>
                    <div class="notification-item-meta">{{ $d['project_title'] ?? '' }} · {{ $n->created_at?->diffForHumans() }}</div>
                </div>
            @empty
                <div class="notification-empty">No unread notifications</div>
            @endforelse
        </div>

        <div class="notification-menu-footer">
            <a href="{{ route($rolePrefix . '.notifications') }}" class="notification-view-all">View all notifications</a>
        </div>
    </div>
</div>

<style>
    .notification-dropdown { line-height: 0; }
    .notification-bell:focus { box-shadow: none; }
    .notification-badge {
        background: #ef4444;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 5px;
        min-width: 16px;
        line-height: 1.2;
    }
    .notification-menu {
        border: 1px solid rgba(0,0,0,0.08);
        border-radius: 12px;
        box-shadow: 0 18px 45px rgba(10,20,50,0.14);
        padding: 0;
        overflow: hidden;
        max-height: 480px;
        display: flex;
        flex-direction: column;
    }
    .notification-menu-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        border-bottom: 1px solid rgba(0,0,0,0.06);
        font-weight: 700;
        font-size: 14px;
    }
    .notification-mark-all { font-size: 12px; color: #2563eb; text-decoration: none; }
    .notification-menu-body { overflow-y: auto; max-height: 340px; }
    .notification-item {
        padding: 11px 16px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        cursor: pointer;
        transition: background 0.15s;
    }
    .notification-item:hover { background: #f8fafc; }
    .notification-item.unread { background: #eff6ff; }
    .notification-item.unread:hover { background: #dbeafe; }
    .notification-item-title {
        font-size: 13px;
        font-weight: 600;
        color: #111827;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .notification-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #2563eb;
        display: inline-block;
        flex: none;
    }
    .notification-item-meta { font-size: 12px; color: #6b7280; margin-top: 2px; }
    .notification-empty { padding: 22px 16px; text-align: center; color: #9ca3af; font-size: 13px; }
    .notification-menu-footer {
        padding: 10px 16px;
        border-top: 1px solid rgba(0,0,0,0.06);
        text-align: center;
    }
    .notification-view-all { font-size: 13px; color: #2563eb; text-decoration: none; font-weight: 600; }
</style>

<script>
    document.querySelectorAll('.notification-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var url = item.dataset.url;
            if (!url) return;
            fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json'
                }
            }).then(function () { location.reload(); });
        });
    });

    document.querySelectorAll('.notification-mark-all').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fetch(btn.dataset.url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json'
                }
            }).then(function () { location.reload(); });
        });
    });
</script>

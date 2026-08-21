{{-- In-app notification bell (gaps #12). Included in the .profile-section of all three
     role layouts. Self-contained: inline SVG icon (no icon-font/CDN dependency), own
     open/close state, and viewport-safe positioning so it can never be cut off or stuck
     open. Mark-read / mark-all-read post to the role-scoped routes. --}}
@php
    $notifUser = auth()->user();
    $unreadCount = $notifUser->unreadNotifications()->count();
    $recentNotifs = $notifUser->unreadNotifications()->latest()->take(5)->get();
    $rolePrefix = $notifUser->role; // admin | staff | contractor
@endphp

<div class="notification-dropdown" id="notification-dropdown">
    <button type="button" class="notification-bell" id="notification-bell"
            aria-expanded="false" title="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        @if($unreadCount > 0)
            <span class="notification-badge">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    <div class="notification-menu" id="notification-menu">
        <div class="notification-menu-header">
            <span>Notifications</span>
            @if($unreadCount > 0)
                <button type="button" class="notification-mark-all"
                        data-url="{{ route($rolePrefix . '.notifications.readAll') }}">
                    Mark all read
                </button>
            @endif
        </div>

        <div class="notification-menu-body">
            @forelse($recentNotifs as $n)
                @php
                    $d = $n->data;
                    $nPid = $d['project_id'] ?? null;
                    $nRoute = match ($rolePrefix) {
                        'staff'      => 'staff.projects.detail',
                        'contractor' => 'projects.show',
                        default      => 'admin.projects.detail',
                    };
                    $nAnchor = match (true) {
                        str_contains($n->type, 'ExtensionStatusChanged')      => '#extensions',
                        str_contains($n->type, 'IssueReported')              => '#issues',
                        str_contains($n->type, 'RiskReported')               => '#risks',
                        str_contains($n->type, 'OverdueInspectionReminder')  => '#baseline-schedule',
                        default => '#overview',
                    };
                    $nUrl = $nPid ? route($nRoute, $nPid) . $nAnchor : '';
                @endphp
                <div class="notification-item {{ $n->read_at ? '' : 'unread' }}"
                     data-id="{{ $n->id }}"
                     data-url="{{ route($rolePrefix . '.notifications.read', $n->id) }}"
                     data-navigate-url="{{ $nUrl }}">
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
                    <div class="notification-item-meta">{{ $d['project_title'] ?? '' }}{{ $n->type === \App\Notifications\OverdueInspectionReminder::class ? ' · ' . ($d['month_display'] ?? $d['month_label'] ?? '') : '' }} · {{ $n->created_at?->diffForHumans() }}</div>
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
    .notification-dropdown {
        position: relative;
        display: inline-block;
        line-height: 0;
    }

    .notification-bell {
        position: relative;
        width: 40px;
        height: 40px;
        padding: 0;
        border: none;
        border-radius: 50%;
        background: rgba(255,255,255,0.12);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s, transform 0.1s;
    }
    .notification-bell:hover { background: rgba(255,255,255,0.22); }
    .notification-bell:focus { outline: none; box-shadow: 0 0 0 3px rgba(96,165,250,0.45); }
    .notification-bell:active { transform: scale(0.95); }
    .notification-bell svg {
        width: 20px;
        height: 20px;
        display: block;
    }

    .notification-badge {
        position: absolute;
        top: 3px;
        right: 3px;
        background: #ef4444;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        line-height: 1.2;
        padding: 2px 5px;
        min-width: 16px;
        border-radius: 999px;
        box-shadow: 0 0 0 2px rgba(26,37,64,0.6);
    }

    .notification-menu {
        display: none;
        position: absolute;
        top: calc(100% + 10px);
        right: 0;
        width: min(340px, calc(100vw - 24px));
        max-height: 480px;
        overflow: hidden;
        background: #fff;
        border: 1px solid rgba(0,0,0,0.08);
        border-radius: 12px;
        box-shadow: 0 18px 45px rgba(10,20,50,0.16);
        z-index: 1080;
        display: none;
        flex-direction: column;
        margin: 0;
    }
    .notification-dropdown.open .notification-menu {
        display: flex;
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
    .notification-mark-all {
        font-size: 12px;
        color: #2563eb;
        background: none;
        border: none;
        cursor: pointer;
        text-decoration: none;
        padding: 0;
    }
    .notification-mark-all:hover { text-decoration: underline; }

    .notification-menu-body { overflow-y: auto; max-height: 340px; }

    .notification-item {
        padding: 11px 16px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        cursor: pointer;
        transition: background 0.15s;
        line-height: 1.4;
    }
    .notification-item:last-child { border-bottom: none; }
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

    .notification-empty {
        padding: 22px 16px;
        text-align: center;
        color: #9ca3af;
        font-size: 13px;
    }

    .notification-menu-footer {
        padding: 10px 16px;
        border-top: 1px solid rgba(0,0,0,0.06);
        text-align: center;
    }
    .notification-view-all {
        font-size: 13px;
        color: #2563eb;
        text-decoration: none;
        font-weight: 600;
    }
    .notification-view-all:hover { text-decoration: underline; }
</style>

<script>
(function () {
    var dropdown = document.getElementById('notification-dropdown');
    if (!dropdown) return;

    var bell = document.getElementById('notification-bell');
    var menu = document.getElementById('notification-menu');

    function open() {
        dropdown.classList.add('open');
        bell.setAttribute('aria-expanded', 'true');
    }

    function close() {
        dropdown.classList.remove('open');
        bell.setAttribute('aria-expanded', 'false');
    }

    bell.addEventListener('click', function (e) {
        e.stopPropagation();
        if (dropdown.classList.contains('open')) {
            close();
        } else {
            open();
        }
    });

    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target)) {
            close();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            close();
        }
    });

    dropdown.querySelectorAll('.notification-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var url = item.dataset.url;
            var navigateUrl = item.dataset.navigateUrl;
            if (!url) return;
            fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json'
                }
            }).then(function () {
                if (navigateUrl) {
                    window.location.href = navigateUrl;
                } else {
                    location.reload();
                }
            });
        });
    });

    dropdown.querySelectorAll('.notification-mark-all').forEach(function (btn) {
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
})();
</script>

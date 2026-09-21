/**
 * Notification bell — open/close, mark read, and AUTO-REFRESH.
 *
 * Markup: resources/views/notifications/partials/bell.blade.php (all 3 layouts).
 * Data:   GET  {role}/notifications/recent  → { unread_count, notifications[] }
 *         (shaped by NotificationController::format via NotificationPresenter).
 *
 * Auto-refresh (restudy P-5, 2026-09-21 — textfiles/notification_monitoring_restudy_plan.md):
 * the bell used to be drawn once at page load, so a notice created while a page
 * was open never showed until the next navigation. Now it re-reads /recent every
 * 60 s while the tab is visible, and right away when the tab regains focus
 * (at most once per 10 s). While the menu is OPEN only the badge is updated —
 * the list is swapped on the next close, so an item never moves under the cursor.
 */
(function () {
    var dropdown = document.getElementById('notification-dropdown');
    if (!dropdown) return;

    var bell = document.getElementById('notification-bell');
    var badge = document.getElementById('notification-badge');
    var markAll = document.getElementById('notification-mark-all');
    var body = document.getElementById('notification-menu-body');
    var recentUrl = dropdown.dataset.recentUrl;

    var POLL_MS = 60000;      // regular refresh while the tab is visible
    var MIN_GAP_MS = 10000;   // focus/visibility refreshes are throttled to this
    var lastFetch = Date.now();
    var pendingList = null;   // list data waiting for the menu to close

    // CSRF header for the POST endpoints
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    // POST helper for read / read-all
    function post(url) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' }
        });
    }

    function isOpen() { return dropdown.classList.contains('open'); }

    // The layouts' `.topbar > * { z-index: 2 }` gives the bell no stacking level of
    // its own against the page below, so a page element with a higher z-index (the
    // dashboard banner is 30) was drawn OVER the open menu (user report
    // 2026-09-21). While the menu is open, lift the whole top bar above the page —
    // 1040 stays under Bootstrap modals (1050+), so a modal still covers it.
    var topbar = dropdown.closest('.topbar');
    var TOPBAR_OPEN_Z = '1040';

    function open() {
        dropdown.classList.add('open');
        bell.setAttribute('aria-expanded', 'true');
        if (topbar) topbar.style.zIndex = TOPBAR_OPEN_Z;
    }

    // Close the menu; a list that arrived while it was open is drawn now
    function close() {
        dropdown.classList.remove('open');
        bell.setAttribute('aria-expanded', 'false');
        if (topbar) topbar.style.zIndex = '';
        if (pendingList) {
            renderList(pendingList);
            pendingList = null;
        }
    }

    // Escape text for innerHTML
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Badge + "Mark all read" visibility from the unread count
    function renderCount(count) {
        if (!badge) return;
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.hidden = count <= 0;
        if (markAll) markAll.hidden = count <= 0;
    }

    // Rebuild the 5-item list from /recent JSON
    function renderList(items) {
        if (!body) return;
        if (!items.length) {
            body.innerHTML = '<div class="notification-empty">No unread notifications</div>';
            return;
        }
        body.innerHTML = items.map(function (n) {
            return '<div class="notification-item' + (n.read ? '' : ' unread') + '"'
                + ' data-id="' + esc(n.id) + '"'
                + ' data-url="' + esc(n.read_url) + '"'
                + ' data-navigate-url="' + esc(n.url) + '">'
                + '<div class="notification-item-title">' + esc(n.label)
                + (n.read ? '' : ' <span class="notification-dot"></span>') + '</div>'
                + '<div class="notification-item-meta">' + esc(n.meta) + ' · ' + esc(n.created) + '</div>'
                + '</div>';
        }).join('');
    }

    // Fetch /recent and refresh the bell (silently ignores failures — next poll retries)
    function refresh() {
        if (!recentUrl) return;
        lastFetch = Date.now();
        fetch(recentUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.success) return;
                renderCount(data.unread_count || 0);
                if (isOpen()) {
                    pendingList = data.notifications || [];
                } else {
                    renderList(data.notifications || []);
                }
            })
            .catch(function () { /* offline / session ended — keep what is shown */ });
    }

    bell.addEventListener('click', function (e) {
        e.stopPropagation();
        if (isOpen()) close(); else open();
    });

    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && isOpen()) close();
    });

    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.keyCode === 27) && isOpen()) close();
    });

    // Item click (delegated — the list is re-rendered by polling): mark read, then go
    body.addEventListener('click', function (e) {
        var item = e.target.closest('.notification-item');
        if (!item || !item.dataset.url) return;
        var navigateUrl = item.dataset.navigateUrl;
        post(item.dataset.url).then(function () {
            if (navigateUrl) window.location.href = navigateUrl;
            else location.reload();
        });
    });

    if (markAll) {
        markAll.addEventListener('click', function () {
            post(markAll.dataset.url).then(function () { location.reload(); });
        });
    }

    // Regular poll — skipped while the tab is hidden (no requests from background tabs)
    setInterval(function () {
        if (document.visibilityState === 'visible') refresh();
    }, POLL_MS);

    // Refresh as soon as the user comes back to the tab
    function onReturn() {
        if (document.visibilityState === 'visible' && Date.now() - lastFetch >= MIN_GAP_MS) refresh();
    }
    document.addEventListener('visibilitychange', onReturn);
    window.addEventListener('focus', onReturn);
})();

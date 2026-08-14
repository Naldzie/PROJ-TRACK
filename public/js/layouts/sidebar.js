/* PROJ-TRACK collapsible sidebar
   Desktop: minimize to an icon rail (body.sidebar-collapsed)
   Mobile : off-canvas drawer toggled by body.sidebar-open + shared backdrop */
(function () {
    var KEY = 'proj-track:sidebar:collapsed';
    var toggles = document.querySelectorAll('#sidebarToggle, #sidebarReopen');
    var media = window.matchMedia('(max-width: 991.98px)');

    function isMobile() {
        return media.matches;
    }

    /* Shared overlay backdrop (hidden by default, shown only on mobile via CSS) */
    var backdrop = document.createElement('div');
    backdrop.className = 'sidebar-backdrop';
    document.body.appendChild(backdrop);

    function setCollapsed(collapsed) {
        if (isMobile()) {
            // The drawer always renders the full sidebar; minimize is desktop-only.
            document.body.classList.remove('sidebar-collapsed');
            return;
        }
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        toggles.forEach(function (t) {
            if (t) t.setAttribute('aria-expanded', String(!collapsed));
        });
    }

    function setMobileOpen(open) {
        document.body.classList.toggle('sidebar-open', open);
        backdrop.classList.toggle('show', open);
        document.body.style.overflow = open ? 'hidden' : '';
        toggles.forEach(function (t) {
            if (t) t.setAttribute('aria-expanded', String(!open));
        });
    }

    function closeMobile() {
        setMobileOpen(false);
    }

    /* Initial state */
    var stored = localStorage.getItem(KEY);
    if (isMobile()) {
        setCollapsed(false);
    } else {
        setCollapsed(stored === null ? false : stored === '1');
    }

    /* Recompute on crossing the breakpoint */
    media.addEventListener('change', function () {
        closeMobile();
        setCollapsed(isMobile() ? false : (localStorage.getItem(KEY) === '1'));
    });

    toggles.forEach(function (toggle) {
        if (!toggle) return;
        toggle.addEventListener('click', function () {
            if (isMobile()) {
                setMobileOpen(!document.body.classList.contains('sidebar-open'));
            } else {
                var next = !document.body.classList.contains('sidebar-collapsed');
                setCollapsed(next);
                localStorage.setItem(KEY, next ? '1' : '0');
            }
        });
    });

    /* Close the drawer on backdrop click, nav selection, or Escape */
    backdrop.addEventListener('click', closeMobile);
    document.querySelectorAll('.sidebar a').forEach(function (a) {
        a.addEventListener('click', closeMobile);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobile();
    });

    /* Icon tooltips while the rail is minimized (desktop only) */
    document.querySelectorAll('.sidebar a, .sidebar-footer .btn-logout').forEach(function (el) {
        var label = el.textContent.trim();
        if (!el.getAttribute('title') && label) {
            el.setAttribute('title', label);
        }
    });
})();
/**
 * project-monitor-columns.js
 * ------------------------------------------------------------------
 * Frontend-only enhancement for the Project Monitor table.
 * - Column visibility dropdown (show/hide per column, no page reload)
 * - Persists selection in localStorage under `projtrack_project_columns`
 * - Row action (kebab) menus
 *
 * Does NOT touch routes, controllers, queries, or existing filter logic.
 * Depends only on `data-col` attributes present on `#projectsTable` th/td.
 */
(function () {
    'use strict';

    // Storage key for column visibility (non-sensitive UI preference only).
    var STORAGE_KEY = 'projtrack_project_columns';

    // Canonical column order + friendly labels. `actions` is locked visible.
    var ALL_COLUMNS = [
        { key: 'code',     label: 'Code' },
        { key: 'title',    label: 'Title' },
        { key: 'category', label: 'Category' },
        { key: 'status',   label: 'Status' },
        { key: 'health',   label: 'Health' },
        { key: 'progress', label: 'Progress' },
        { key: 'start',    label: 'Start Date' },
        { key: 'duration', label: 'Duration' },
        { key: 'deadline', label: 'Deadline' },
        { key: 'days',     label: 'Days Left' },
        { key: 'manager',  label: 'Manager' },
        { key: 'cost',     label: 'Cost' },
        { key: 'actions',  label: 'Actions', locked: true }
    ];

    function table() {
        return document.getElementById('projectsTable');
    }

    // Load saved visibility map, or null when nothing saved yet.
    function loadState() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') return null;
            return parsed;
        } catch (e) {
            return null;
        }
    }

    // Persist visibility map (keys present in ALL_COLUMNS only).
    function saveState(state) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) { /* storage unavailable — fail silently */ }
    }

    // Default: every column visible.
    function defaultState() {
        var s = {};
        ALL_COLUMNS.forEach(function (c) { s[c.key] = true; });
        return s;
    }

    // Apply a visibility map to the table via data-col selectors.
    function applyState(state) {
        var t = table();
        if (!t) return;
        ALL_COLUMNS.forEach(function (c) {
            if (c.locked) return; // Actions column always stays visible.
            var visible = state[c.key] !== false;
            t.querySelectorAll('[data-col="' + c.key + '"]').forEach(function (cell) {
                cell.style.display = visible ? '' : 'none';
            });
        });
        syncCheckboxes(state);
        updateEmptyRowColspan();
    }

    // Keep the "no projects" empty row spanning the visible columns.
    function updateEmptyRowColspan() {
        var t = table();
        if (!t) return;
        var visibleCount = t.querySelectorAll('thead th:not([style*="none"])').length;
        if (!visibleCount) visibleCount = ALL_COLUMNS.length;
        t.querySelectorAll('tbody tr').forEach(function (row) {
            if (row.children.length === 1) {
                row.children[0].setAttribute('colspan', String(visibleCount));
            }
        });
    }

    // Reflect state in the dropdown checkboxes.
    function syncCheckboxes(state) {
        document.querySelectorAll('#pmColumnsList input[data-col-key]').forEach(function (box) {
            var key = box.getAttribute('data-col-key');
            box.checked = state[key] !== false;
        });
    }

    // Build the checkbox list from ALL_COLUMNS (only for cols in the DOM).
    function buildList(state) {
        var list = document.getElementById('pmColumnsList');
        var t = table();
        if (!list || !t) return;
        list.innerHTML = '';
        ALL_COLUMNS.forEach(function (c) {
            if (c.locked) return;
            // Skip columns that do not exist in this table variant.
            if (!t.querySelector('thead th[data-col="' + c.key + '"]')) return;
            var label = document.createElement('label');
            label.className = 'pm-columns-item';
            var box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = state[c.key] !== false;
            box.setAttribute('data-col-key', c.key);
            box.addEventListener('change', function () {
                var current = loadState() || defaultState();
                current[c.key] = box.checked;
                saveState(current);
                applyState(current);
            });
            var text = document.createElement('span');
            text.textContent = c.label;
            label.appendChild(box);
            label.appendChild(text);
            list.appendChild(label);
        });
    }

    // Columns popover open/close behaviour.
    function initColumnsPopover() {
        var btn = document.getElementById('pmColumnsBtn');
        var pop = document.getElementById('pmColumnsPop');
        if (!btn || !pop) return;

        function close() {
            pop.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }
        function open() {
            pop.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (pop.hidden) open(); else close();
        });
        pop.addEventListener('click', function (e) { e.stopPropagation(); });
        document.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(); closeAllRowMenus(); }
        });

        var showAll = document.getElementById('pmColumnsShowAll');
        var hideAll = document.getElementById('pmColumnsHideAll');
        var reset = document.getElementById('pmColumnsReset');

        if (showAll) showAll.addEventListener('click', function () {
            var s = defaultState();
            saveState(s);
            applyState(s);
        });
        if (hideAll) hideAll.addEventListener('click', function () {
            var s = {};
            ALL_COLUMNS.forEach(function (c) { s[c.key] = (c.key === 'actions'); });
            // Keep at least Title visible so the table stays meaningful.
            s.title = true;
            saveState(s);
            applyState(s);
        });
        if (reset) reset.addEventListener('click', function () {
            try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
            var s = defaultState();
            applyState(s);
        });
    }

    // The kebab button that opened the floating menu (null when closed).
    var openKebab = null;

    function closeAllRowMenus() {
        var m = document.getElementById('pmRowMenu');
        if (m) m.hidden = true;
        document.querySelectorAll('.pm-kebab').forEach(function (b) {
            b.setAttribute('aria-expanded', 'false');
        });
        openKebab = null;
    }

    // Position the floating menu next to the kebab button. Uses
    // position:fixed + viewport coords so the menu is never clipped by
    // the table scroll container nor painted under neighbouring rows.
    function positionRowMenu(m, kebab) {
        var r = kebab.getBoundingClientRect();
        m.style.visibility = 'hidden';
        m.hidden = false;
        var mw = m.offsetWidth || 150;
        var mh = m.offsetHeight || 100;
        var top = r.bottom + 6;
        if (top + mh > window.innerHeight - 8) {
            top = Math.max(8, r.top - mh - 6);
        }
        var left = r.right - mw;
        left = Math.max(8, Math.min(left, window.innerWidth - mw - 8));
        m.style.top = top + 'px';
        m.style.left = left + 'px';
        m.style.visibility = '';
    }

    // Fill the single floating menu from the kebab's data-* attributes
    // (authorization already enforced server-side via @can) and show it.
    function openRowMenu(kebab) {
        var m = document.getElementById('pmRowMenu');
        if (!m) return;
        var view = document.getElementById('pmRowMenuView');
        var edit = document.getElementById('pmRowMenuEdit');
        var arch = document.getElementById('pmRowMenuArchive');

        if (view) view.setAttribute('href', kebab.getAttribute('data-view-url') || '#');

        if (edit) {
            if (kebab.hasAttribute('data-edit-id')) {
                edit.hidden = false;
                edit.setAttribute('data-project-id', kebab.getAttribute('data-edit-id'));
            } else {
                edit.hidden = true;
            }
        }

        if (arch) {
            if (kebab.hasAttribute('data-archive-url')) {
                arch.hidden = false;
                arch.onclick = function () {
                    var url = kebab.getAttribute('data-archive-url');
                    var title = kebab.getAttribute('data-archive-title') || '';
                    closeAllRowMenus();
                    window.archiveProject(url, title);
                };
            } else {
                arch.hidden = true;
                arch.onclick = null;
            }
        }

        positionRowMenu(m, kebab);
        kebab.setAttribute('aria-expanded', 'true');
        openKebab = kebab;
    }

    // Row kebab menus (event delegation — works with pagination re-renders).
    function initRowMenus() {
        document.addEventListener('click', function (e) {
            var kebab = e.target.closest ? e.target.closest('.pm-kebab') : null;
            if (kebab) {
                e.stopPropagation();
                if (openKebab === kebab) {
                    closeAllRowMenus(); // toggle shut
                } else {
                    closeAllRowMenus();
                    openRowMenu(kebab);
                }
                return;
            }
            var m = document.getElementById('pmRowMenu');
            var insideMenu = m && e.target.closest ? e.target.closest('#pmRowMenu') : null;
            if (insideMenu) {
                // Let the click (navigation / modal / archive) proceed,
                // then close the menu — it must never trap the choices.
                setTimeout(closeAllRowMenus, 0);
                return;
            }
            closeAllRowMenus();
        });
        // A fixed menu would detach from its button on scroll/resize.
        document.addEventListener('scroll', function () {
            if (openKebab) closeAllRowMenus();
        }, true);
        window.addEventListener('resize', closeAllRowMenus);
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!table()) return;
        var state = loadState() || defaultState();
        buildList(state);
        applyState(state);
        initColumnsPopover();
        initRowMenus();
    });
})();

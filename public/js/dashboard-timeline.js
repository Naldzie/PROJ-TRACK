/* ══════════════════════════════════════════════════════════════
   DASHBOARD TIMELINE JS
   public/js/dashboard-timeline.js

   Used by: resources/views/dashboard/partials/timeline-widget.blade.php
   Requires: .tl-wrap with data-quarter/year-start/end (set in Blade).

   Contains:
     - tlSwitchRange()  Quarter / year toggle — re-renders month labels,
                        bar geometry, gridlines and the today marker
     - Tooltip          Planned vs actual on bar hover (single #tlTip node)
     - Ext filter       Extensions KPI toggles extension-rows-only view

   Row clicks need no JS — each .tl-row is an <a> to the detail view.
   ══════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var DAY = 86400000;

    // ── Parse a Y-m-d string as a local-midnight date (no TZ shift) ──
    function tlDate(str) {
        if (!str) return null;
        var p = str.split('-');
        return new Date(+p[0], +p[1] - 1, +p[2]);
    }

    // ── Whole days between two dates ──
    function tlDiff(a, b) {
        return Math.round((b - a) / DAY);
    }

    // ── Short range label, e.g. "Jul 1 – Sep 30, 2026" ──
    function tlRangeLabel(start, end, withYear) {
        var s = MONTHS[start.getMonth()] + ' ' + start.getDate();
        var e = MONTHS[end.getMonth()] + ' ' + end.getDate() + ', ' + end.getFullYear();
        return withYear ? s + ', ' + start.getFullYear() + ' – ' + e : s + ' – ' + e;
    }

    // ── Re-render the whole strip for the given range ─────────────────
    // Called by onclick="tlSwitchRange(this)" on each .tl-toggle-btn.
    window.tlSwitchRange = function (btn) {
        var wrap = document.querySelector('.tl-wrap');
        if (!wrap) return;

        var range = btn.dataset.range; // 'quarter' | 'year'
        wrap.querySelectorAll('.tl-toggle-btn').forEach(function (b) {
            b.classList.toggle('active', b === btn);
        });

        var start = tlDate(wrap.dataset[range + 'Start']);
        var end = tlDate(wrap.dataset[range + 'End']);
        if (!start || !end) return;
        var rangeDays = Math.max(1, tlDiff(start, end) + 1);
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var todayIn = today >= start && today <= end;

        // Header title + subtitle follow the active range.
        document.getElementById('tlTitle').textContent =
            range === 'quarter' ? 'This quarter' : 'This year';
        document.getElementById('tlRange').textContent =
            range === 'quarter'
                ? tlRangeLabel(start, end, false)
                : MONTHS[start.getMonth()] + ' 1 – ' + MONTHS[end.getMonth()] +
                  ' ' + end.getDate() + ', ' + end.getFullYear();

        // Month segments (day-proportional) + their boundary positions.
        var segs = [];
        var cur = new Date(start.getFullYear(), start.getMonth(), 1);
        while (cur <= end) {
            var mStart = new Date(cur.getFullYear(), cur.getMonth(), 1);
            var mEnd = new Date(cur.getFullYear(), cur.getMonth() + 1, 0);
            var segStart = mStart < start ? start : mStart;
            var segEnd = mEnd > end ? end : mEnd;
            segs.push({
                label: MONTHS[cur.getMonth()],
                width: (tlDiff(segStart, segEnd) + 1) / rangeDays * 100,
                line: tlDiff(start, segStart) / rangeDays * 100
            });
            cur = new Date(cur.getFullYear(), cur.getMonth() + 1, 1);
        }

        // Header month labels.
        document.getElementById('tlMonths').innerHTML = segs.map(function (s) {
            return '<span class="tl-month" style="width:' + s.width.toFixed(2) + '%">' +
                s.label + '</span>';
        }).join('');

        // Per-row geometry: bar position, gridlines, today marker.
        document.querySelectorAll('#tlRows .tl-row').forEach(function (row, idx) {
            var bar = row.querySelector('.tl-bar');
            if (!bar) return;
            var pStart = tlDate(bar.dataset.start);
            var pEnd = tlDate(bar.dataset.end);
            var left, width;

            if (pEnd && pEnd < start) {
                left = 0; width = 2; // Overdue: pin a sliver at the left edge.
            } else if (pStart && pStart > end) {
                left = 98; width = 2; // Future: pin a sliver at the right edge.
            } else {
                var bStart = (!pStart || pStart < start) ? start : pStart;
                var bEnd = (!pEnd || pEnd > end) ? end : pEnd;
                left = tlDiff(start, bStart) / rangeDays * 100;
                width = Math.max(2, (tlDiff(bStart, bEnd) + 1) / rangeDays * 100);
            }
            bar.style.left = left.toFixed(2) + '%';
            bar.style.width = width.toFixed(2) + '%';

            // Replay the grow-in animation so the range switch feels
            // alive (staggered, capped so long lists stay snappy).
            // Skipped when the user prefers reduced motion.
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                bar.style.animationDelay = Math.min(idx * 30, 300) + 'ms';
                bar.style.animation = 'none';
                void bar.offsetWidth; // reflow flushes the reset above
                bar.style.animation = '';
            }

            var grid = row.querySelector('.tl-grid');
            if (grid) {
                grid.innerHTML = segs.filter(function (s) {
                    return s.line > 0;
                }).map(function (s) {
                    return '<span class="tl-gridline" style="left:' +
                        s.line.toFixed(2) + '%"></span>';
                }).join('');
            }

            var track = row.querySelector('.tl-track');
            var mark = row.querySelector('.tl-today');
            if (todayIn && track) {
                var pct = (tlDiff(start, today) / rangeDays * 100).toFixed(2) + '%';
                if (mark) {
                    mark.style.left = pct;
                } else {
                    mark = document.createElement('span');
                    mark.className = 'tl-today';
                    mark.style.left = pct;
                    track.appendChild(mark);
                }
            } else if (mark) {
                mark.remove();
            }
        });
    };

    // ── Planned-vs-actual tooltip (delegated, one node) ───────────────
    document.addEventListener('DOMContentLoaded', function () {
        var wrap = document.querySelector('.tl-wrap');
        if (!wrap) return;
        var tip = document.getElementById('tlTip');
        if (!tip) return;

        // Toggle buttons are wired here (tlSwitchRange stays global as well,
        // matching the dbSwitchTab onclick convention used by sibling tabs).
        wrap.querySelectorAll('.tl-toggle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                window.tlSwitchRange(btn);
            });
        });

        // Stagger the initial row entrance (capped at ~0.5s total).
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.querySelectorAll('#tlRows .tl-row').forEach(function (row, i) {
                row.style.animationDelay = Math.min(i * 45, 495) + 'ms';
            });
        }

        var extBtn = document.getElementById('tlExtFilter');
        if (extBtn) {
            extBtn.addEventListener('click', function () {
                var on = extBtn.classList.toggle('active');
                extBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
                document.querySelectorAll('#tlRows .tl-row').forEach(function (row) {
                    row.classList.toggle('is-hidden',
                        on && row.dataset.hasExt !== '1');
                });
            });
        }

        // Hover a bar → "Planned X% · Actual Y%" follows the cursor.
        document.getElementById('tlRows').addEventListener('mousemove', function (e) {
            var bar = e.target.closest ? e.target.closest('.tl-bar') : null;
            if (!bar) {
                tip.hidden = true;
                return;
            }
            tip.innerHTML = '<strong>Planned ' + bar.dataset.planned + '%</strong>' +
                ' · Actual ' + bar.dataset.actual + '%';
            tip.hidden = false;
            var x = Math.min(e.clientX + 14, window.innerWidth - tip.offsetWidth - 10);
            var y = e.clientY - tip.offsetHeight - 12;
            tip.style.left = Math.max(8, x) + 'px';
            tip.style.top = Math.max(8, y) + 'px';
        });
        document.getElementById('tlRows').addEventListener('mouseleave', function () {
            tip.hidden = true;
        });
    });
})();

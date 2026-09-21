{{--
╔══════════════════════════════════════════════════════════════════╗
║  CALENDAR WIDGET                                                 ║
║  Real project dates — start (■) + deadline (●) + monthly         ║
║  inspection (ring) markers.                                      ║
║                                                                  ║
║  Data: $calendarProjects from DashboardCalendarService           ║
║  (DashboardController@index for admin/staff, contractor          ║
║  DashboardController@index for contractors). Each item:          ║
║  id, project_id, code, name, contractor, location, date,         ║
║  kind (start|deadline|inspection), kindLabel, status,            ║
║  project_status, planned (%), progress (%), inspectionDate,      ║
║  monthLabel, startDate, dueDate, detailUrl.                      ║
║                                                                  ║
║  Google push runs server-side (observers + queued job). The      ║
║  "Add to Google" button below is the no-consent fallback         ║
║  (template URL), alongside the .ics download route.              ║
╚══════════════════════════════════════════════════════════════════╝
--}}
@php $calendarProjects = $calendarProjects ?? []; @endphp
<div class="cal-tab-wrap">

    {{-- ── HEADER ── --}}
    <div class="cal-widget-header">
        <div class="cal-widget-title">
            <i class="bi bi-calendar-event"></i>
            <span id="calendar-title">Project Calendar</span>
        </div>
        <div class="cal-nav-controls">
            <button class="calendar-nav-btn" id="prev-month-btn" onclick="previousMonth()">
                <i class="bi bi-chevron-left"></i> Prev
            </button>
            <span class="calendar-month-year-display" id="month-year-display"></span>
            <button class="calendar-nav-btn" id="next-month-btn" onclick="nextMonth()">
                Next <i class="bi bi-chevron-right"></i>
            </button>
            <button class="calendar-view-toggle" id="view-toggle-btn" onclick="toggleViewMode()">
                <i class="bi bi-grid-3x3-gap me-1"></i> Full Year
            </button>
        </div>
    </div>

    {{-- ── LEGEND ── --}}
    <div class="cal-legend-bar">
        <div class="legend-item">
            <div class="legend-dot" style="background:#2563eb;border-radius:50%;"></div>
            On Track
        </div>
        <div class="legend-item" title="Square markers are project start dates">
            <div class="legend-dot" style="background:#2563eb;border-radius:2px;"></div>
            Start
        </div>
        <div class="legend-item" title="Ringed markers are monthly inspection dates">
            <div class="legend-dot" style="background:#fff;border:1.5px dashed #8b5cf6;border-radius:50%;"></div>
            Inspection
        </div>
    </div>

    @if(empty($calendarProjects))
    <div class="cal-empty-note" style="padding:10px 14px;font-size:12px;color:#64748B;">
        No project dates to show yet — dates appear here once projects have a start date, deadline, or baseline inspection month.
    </div>
    @endif

    {{-- ── MONTH VIEW (default) ── --}}
    <div class="month-view-container" id="month-view-container">
        <div class="single-month-calendar" id="single-month-calendar">
            {{-- Built by JS --}}
        </div>
    </div>

    {{-- ── YEAR VIEW (hidden) ── --}}
    <div class="year-calendar-grid" id="calendar-grid">
        {{-- Built by JS --}}
    </div>

</div>{{-- /.cal-tab-wrap --}}


{{-- ── EVENT DETAILS MODAL ── --}}
<div class="modal-overlay" id="project-modal" onclick="closeProjectModal(event)">
    <div class="project-details-modal" onclick="event.stopPropagation()">

        <div class="cal-modal-header">
            <div>
                <div class="cal-modal-title" id="modal-project-code"></div>
                <div class="cal-modal-sub" id="modal-project-contractor"></div>
            </div>
            <button class="modal-close-btn" onclick="closeProjectModal()">✕</button>
        </div>

        <div class="modal-content">

            <div class="modal-row">
                <div class="modal-label">Project</div>
                <div class="modal-value" id="modal-project-name"></div>
            </div>

            <div class="modal-row">
                <div class="modal-label">Event</div>
                <div class="modal-value">
                    <span class="status-badge" id="modal-kind-badge"></span>
                </div>
            </div>

            <div class="modal-row">
                <div class="modal-label">Status</div>
                <div class="modal-value">
                    <span class="status-badge" id="modal-project-status"></span>
                </div>
            </div>

            <div class="modal-row">
                <div class="modal-label">Date</div>
                <div class="modal-value" id="modal-project-deadline"></div>
            </div>

            <div class="modal-row" id="modal-startdue-row" style="display:none">
                <div class="modal-label">Timeline</div>
                <div class="modal-value" id="modal-startdue-text" style="font-size:12px;color:#475569;"></div>
            </div>

            {{-- Monthly progress: planned vs actual cumulative % --}}
            <div class="modal-row">
                <div class="modal-label">Progress</div>
                <div style="width:100%">
                    <div class="cal-progress-label">
                        <span>Cumulative progress</span>
                        <span id="modal-project-progress-text" style="font-weight:700;color:#1f2937;"></span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-bar-fill" id="modal-project-progress" style="width:0%;"></div>
                    </div>
                    <div class="cal-progress-split">
                        <span>Planned: <strong id="modal-planned-pct">—</strong></span>
                        <span>Actual: <strong id="modal-actual-pct">—</strong></span>
                    </div>
                </div>
            </div>

            {{-- Monthly inspection date --}}
            <div class="cal-inspection-row" id="modal-inspection-row">
                <i class="bi bi-clipboard-check"></i>
                <span id="modal-inspection-text">Inspection date: —</span>
            </div>

            <div id="modal-days-deadline" class="days-to-deadline"></div>

            <div style="font-size:11px;color:#64748B;margin-top:8px;">
                Start + deadline dates also sync automatically to the Google Calendars of connected admins, the assigned staff, and the contractor. Not connected? Use the buttons below.
            </div>

        </div>{{-- /.modal-content --}}

        <div class="modal-actions" style="flex-wrap:wrap;gap:8px;">
            <button class="modal-btn primary" id="modal-view-btn">
                <i class="bi bi-box-arrow-up-right me-1"></i> View Full Project
            </button>
            <a class="modal-btn secondary" id="modal-gcal-btn" href="#" target="_blank" rel="noopener" style="text-decoration:none;">
                <i class="bi bi-calendar-plus me-1"></i> Add to Google
            </a>
            <a class="modal-btn secondary" id="modal-ics-btn" href="#" style="text-decoration:none;">
                <i class="bi bi-download me-1"></i> .ics
            </a>
            <button class="modal-btn secondary" onclick="closeProjectModal()">Close</button>
        </div>

    </div>
</div>{{-- /.modal-overlay --}}


<script>
/* ══════════════════════════════════════════════════════════════
   CALENDAR WIDGET JS — real backend dates.
   One dot per event: kind start (■) / deadline (●) / inspection (ring).
   Modal shows the event date plus the project's start → due timeline,
   monthly planned vs actual, and no-consent fallbacks (Add to Google
   template link + .ics download).
   ══════════════════════════════════════════════════════════════ */

// ── DATA (real dates from DashboardCalendarService) ──
const projectsData = @json($calendarProjects);

// ── STATE ──
const calToday    = new Date();
calToday.setHours(0, 0, 0, 0);
let calYear       = calToday.getFullYear();
let calMonth      = calToday.getMonth();
let calViewMode   = 'month';

const calMonthNames = [
    'January','February','March','April','May','June',
    'July','August','September','October','November','December'
];

// ── INIT ──
function initializeCalendar() {
    generateMonthCalendar();
}

// Date-only equality (avoids TZ drift from `new Date('YYYY-MM-DD')`).
function calDayKey(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

// ── MONTH VIEW ──
function generateMonthCalendar() {
    const container  = document.getElementById('single-month-calendar');
    if (!container) return;
    container.innerHTML = '';
    container.appendChild(createMonthCalendar(calYear, calMonth, calMonthNames[calMonth], true));
    document.getElementById('month-year-display').textContent =
        `${calMonthNames[calMonth]} ${calYear}`;
    document.getElementById('calendar-title').textContent =
        `Project Calendar — ${calMonthNames[calMonth]} ${calYear}`;
}

function previousMonth() {
    if (calMonth === 0) { calMonth = 11; calYear--; }
    else calMonth--;
    generateMonthCalendar();
}

function nextMonth() {
    if (calMonth === 11) { calMonth = 0; calYear++; }
    else calMonth++;
    generateMonthCalendar();
}

// ── YEAR VIEW ──
function generateYearCalendar() {
    const grid = document.getElementById('calendar-grid');
    grid.innerHTML = '';
    for (let m = 0; m < 12; m++) {
        grid.appendChild(createMonthCalendar(calYear, m, calMonthNames[m], false));
    }
}

// ── CALENDAR BUILDER ──
function createMonthCalendar(year, month, monthName, isFullMonth) {
    const wrap = document.createElement('div');
    wrap.className = isFullMonth ? 'month-calendar full-month' : 'month-calendar';

    // Header
    const hdr = document.createElement('div');
    hdr.className = 'month-header';
    hdr.textContent = isFullMonth ? monthName : `${monthName} ${year}`;
    wrap.appendChild(hdr);

    // Weekday labels
    const wkRow = document.createElement('div');
    wkRow.className = 'weekdays-row';
    ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(d => {
        const el = document.createElement('div');
        el.className = 'weekday-header';
        el.textContent = d;
        wkRow.appendChild(el);
    });
    wrap.appendChild(wkRow);

    // Grid
    const grid = document.createElement('div');
    grid.className = 'calendar-grid';

    const daysInMonth = new Date(year, month + 1, 0).getDate();
    let startDow      = new Date(year, month, 1).getDay(); // 0=Sun
    if (startDow === 0) startDow = 6; else startDow--; // convert to Mon-start

    // Leading empty cells
    for (let i = 0; i < startDow; i++) {
        const e = document.createElement('div');
        e.className = 'calendar-day empty';
        grid.appendChild(e);
    }

    // Day cells
    for (let day = 1; day <= daysInMonth; day++) {
        const dateObj = new Date(year, month, day);
        const cell    = document.createElement('div');
        cell.className = 'calendar-day';
        if (isCalToday(dateObj)) cell.classList.add('today');

        const num = document.createElement('div');
        num.className = 'day-number';
        num.textContent = day;
        cell.appendChild(num);

        // Event dots — one per calendar event falling on this day.
        // Hovering the DATE previews everything on it (data-daytip);
        // the dots themselves show no popup. Clicking the date opens the
        // first event while each dot stays individually clickable.
        const key = calDayKey(dateObj);
        const hits = projectsData.filter(p => (p.date || p.deadline) === key);
        if (hits.length > 0) {
            cell.classList.add('has-events');
            const tip = hits.slice(0, 6)
                .map(p => `● ${p.code} · ${p.kindLabel || p.kind || ''}`)
                .join('\n') + (hits.length > 6 ? `\n+${hits.length - 6} more…` : '');
            cell.setAttribute('data-daytip', tip);
            cell.onclick = () => showProjectModal(hits[0].id);
            const dotsRow = document.createElement('div');
            dotsRow.className = 'event-dots-container';
            hits.slice(0, 12).forEach(p => {
                const dot = document.createElement('div');
                dot.className   = `event-dot status-${p.status} kind-${p.kind || 'deadline'}`;
                dot.setAttribute('data-event-id', p.id);
                dot.onclick = (e) => { e.stopPropagation(); showProjectModal(p.id); };
                dotsRow.appendChild(dot);
            });
            if (hits.length > 12) {
                const more = document.createElement('div');
                more.style.cssText = 'font-size:9px;color:#64748B;font-weight:700;';
                more.textContent = `+${hits.length - 12}`;
                dotsRow.appendChild(more);
            }
            cell.appendChild(dotsRow);
        }

        grid.appendChild(cell);
    }

    wrap.appendChild(grid);
    return wrap;
}

// ── VIEW TOGGLE ──
function toggleViewMode() {
    const btn       = document.getElementById('view-toggle-btn');
    const monthWrap = document.getElementById('month-view-container');
    const yearGrid  = document.getElementById('calendar-grid');

    if (calViewMode === 'month') {
        calViewMode = 'year';
        monthWrap.classList.add('hidden');
        yearGrid.classList.add('active');
        btn.classList.add('active');
        btn.innerHTML = '<i class="bi bi-chevron-left me-1"></i> Back to Month';
        document.getElementById('calendar-title').textContent =
            `Project Calendar — ${calYear}`;
        generateYearCalendar();
    } else {
        calViewMode = 'month';
        monthWrap.classList.remove('hidden');
        yearGrid.classList.remove('active');
        btn.classList.remove('active');
        btn.innerHTML = '<i class="bi bi-grid-3x3-gap me-1"></i> Full Year';
        generateMonthCalendar();
    }
}

// ── GOOGLE TEMPLATE URL (no-consent fallback, all-day event) ──
function calTemplateUrl(ev) {
    const pad = (n) => String(n).padStart(2, '0');
    const d = new Date(ev.date + 'T00:00:00');
    const start = `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}`;
    const nx = new Date(d.getTime() + 86400000);
    const end = `${nx.getFullYear()}${pad(nx.getMonth() + 1)}${pad(nx.getDate())}`;
    const title = `${ev.kindLabel || 'Event'}: ${ev.name} (${ev.code})`;
    const details = `[${ev.code}] ${ev.name} — ${ev.kindLabel || ''} (${ev.date}) · ${ev.contractor}`;
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        + '&text=' + encodeURIComponent(title)
        + '&dates=' + start + '/' + end
        + '&details=' + encodeURIComponent(details)
        + (ev.location ? '&location=' + encodeURIComponent(ev.location) : '');
}

// ── MODAL ──
function showProjectModal(eventId) {
    const p = projectsData.find(x => String(x.id) === String(eventId));
    if (!p) return;

    const evDate = new Date((p.date || p.deadline) + 'T00:00:00');
    const daysUntil = Math.round((evDate - calToday) / 86400000);
    const isDeadline = (p.kind || 'deadline') === 'deadline';

    // Header
    document.getElementById('modal-project-code').innerHTML =
        `<span class="project-code-badge">${p.code}</span>`;
    document.getElementById('modal-project-contractor').textContent =
        `${p.contractor} · ${p.location}`;

    // Basic details
    document.getElementById('modal-project-name').textContent = p.name;

    const kindBadge = document.getElementById('modal-kind-badge');
    kindBadge.className = 'status-badge status-' + p.status;
    kindBadge.textContent = p.kindLabel || p.kind || 'Event';

    const sb = document.getElementById('modal-project-status');
    sb.className   = 'status-badge status-' + p.status;
    sb.textContent = p.status.charAt(0).toUpperCase()
                   + p.status.slice(1).replace('-', ' ');

    document.getElementById('modal-project-deadline').textContent =
        evDate.toLocaleDateString('en-US', {
            year: 'numeric', month: 'long', day: 'numeric'
        });

    // Start → due timeline
    const tlRow = document.getElementById('modal-startdue-row');
    if (p.startDate || p.dueDate) {
        const fmt = (s) => s ? new Date(s + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
        document.getElementById('modal-startdue-text').textContent =
            `Starts ${fmt(p.startDate)}  →  Due ${fmt(p.dueDate)}`;
        tlRow.style.display = 'flex';
    } else {
        tlRow.style.display = 'none';
    }

    // Monthly progress — cumulative planned vs actual
    const fillEl = document.getElementById('modal-project-progress');
    fillEl.style.width      = p.progress + '%';
    fillEl.style.background = p.progress >= p.planned
        ? '#10b981'                                  // on/ahead of plan → green
        : (p.planned - p.progress > 10
            ? '#dc2626'                              // >10% behind → red
            : '#f59e0b');                            // ≤10% behind → amber

    document.getElementById('modal-project-progress-text').textContent = p.progress + '%';
    document.getElementById('modal-planned-pct').textContent = p.planned + '%';
    document.getElementById('modal-actual-pct').textContent  = p.progress + '%';

    // Inspection date
    const inspRow = document.getElementById('modal-inspection-row');
    if (p.inspectionDate) {
        const inspLabel = new Date(p.inspectionDate + 'T00:00:00').toLocaleDateString('en-US', {
            month: 'long', day: 'numeric', year: 'numeric'
        });
        document.getElementById('modal-inspection-text').textContent =
            'Inspection date: ' + inspLabel + (p.monthLabel ? ` (${p.monthLabel})` : '');
        inspRow.style.display = 'flex';
    } else {
        inspRow.style.display = 'none';
    }

    // Days to event / overdue banner (only deadlines carry overdue meaning)
    const daysDiv = document.getElementById('modal-days-deadline');
    if (p.project_status === 'Completed' || p.status === 'completed') {
        daysDiv.className   = 'days-to-deadline safe';
        daysDiv.textContent = '✓ Project Completed';
    } else if (isDeadline && daysUntil < 0) {
        daysDiv.className   = 'days-to-deadline urgent';
        daysDiv.textContent = `⚠ OVERDUE by ${Math.abs(daysUntil)} day${Math.abs(daysUntil) !== 1 ? 's' : ''}`;
    } else if (daysUntil < 0) {
        daysDiv.className   = 'days-to-deadline safe';
        daysDiv.textContent = `${p.kindLabel || 'Event'} was ${Math.abs(daysUntil)} day${Math.abs(daysUntil) !== 1 ? 's' : ''} ago`;
    } else if (daysUntil <= 7) {
        daysDiv.className   = 'days-to-deadline warning';
        daysDiv.textContent = `⏱ ${daysUntil} day${daysUntil !== 1 ? 's' : ''} until ${p.kindLabel || 'event'}`;
    } else {
        daysDiv.className   = 'days-to-deadline safe';
        daysDiv.textContent = `📅 ${daysUntil} days until ${p.kindLabel || 'event'}`;
    }

    // View button links to the role-aware project detail page
    document.getElementById('modal-view-btn').onclick = () => {
        window.location.href = p.detailUrl || `/admin/projects/${p.project_id}`;
    };

    // Fallbacks: template link (no consent) + .ics download
    document.getElementById('modal-gcal-btn').href = calTemplateUrl(p);
    document.getElementById('modal-ics-btn').href = `/calendar/projects/${p.project_id}/ics`;

    document.getElementById('project-modal').classList.add('active');
}

function closeProjectModal(event) {
    // Only close if clicking the backdrop itself, or no event (button click)
    if (event && event.target.id !== 'project-modal') return;
    document.getElementById('project-modal').classList.remove('active');
}

function isCalToday(date) {
    return date.toDateString() === calToday.toDateString();
}

// ESC to close
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.getElementById('project-modal').classList.remove('active');
    }
});

// ── BOOT ──
document.addEventListener('DOMContentLoaded', initializeCalendar);
</script>

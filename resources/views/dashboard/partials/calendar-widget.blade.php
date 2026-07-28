{{--
╔══════════════════════════════════════════════════════════════════╗
║  CALENDAR WIDGET                                                 ║
║  Project Deadline Calendar — Month view + Full Year toggle       ║
║                                                                  ║
║  CSS is in: public/css/admin/dashboard/calendar-widget.css       ║
║  (or whichever CSS file you moved the dashboard styles into)     ║
║                                                                  ║
║  Progress model: monthly cumulative (not milestone-based).       ║
║    - deadline dot = project's planned end date                   ║
║    - modal shows: planned %, actual %, inspection date           ║
║                                                                  ║
║  TO WIRE BACKEND:                                                ║
║    Replace the hardcoded `projectsData` array in the            ║
║    <script> block with:                                          ║
║      const projectsData = @json($calendarProjects);             ║
║    Each item needs: id, code, name, contractor, location,        ║
║    deadline, status, planned (%), progress (%), inspectionDate   ║
╚══════════════════════════════════════════════════════════════════╝
--}}
@php $calendarProjects = $calendarProjects ?? []; @endphp
<div class="cal-tab-wrap">

    {{-- ── HEADER ── --}}
    <div class="cal-widget-header">
        <div class="cal-widget-title">
            <i class="bi bi-calendar-event"></i>
            <span id="calendar-title">Project Deadlines — June 2026</span>
        </div>
        <div class="cal-nav-controls">
            <button class="calendar-nav-btn" id="prev-month-btn" onclick="previousMonth()">
                <i class="bi bi-chevron-left"></i> Prev
            </button>
            <span class="calendar-month-year-display" id="month-year-display">June 2026</span>
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
            <div class="legend-dot" style="background:#ef4444;"></div>
            Overdue / Critical
        </div>
        <div class="legend-item">
            <div class="legend-dot" style="background:#f59e0b;"></div>
            At Risk (within 7 days)
        </div>
        <div class="legend-item">
            <div class="legend-dot" style="background:#2563eb;"></div>
            On Track
        </div>
        <div class="legend-item">
            <div class="legend-dot" style="background:#10b981;"></div>
            Completed
        </div>
    </div>

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


{{-- ── PROJECT DETAILS MODAL ── --}}
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
                <div class="modal-label">Status</div>
                <div class="modal-value">
                    <span class="status-badge" id="modal-project-status"></span>
                </div>
            </div>

            <div class="modal-row">
                <div class="modal-label">Deadline</div>
                <div class="modal-value" id="modal-project-deadline"></div>
            </div>

            {{-- Monthly progress: planned vs actual cumulative % --}}
            <div class="modal-row">
                <div class="modal-label">This Month</div>
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

        </div>{{-- /.modal-content --}}

        <div class="modal-actions">
            <button class="modal-btn primary" id="modal-view-btn">
                <i class="bi bi-box-arrow-up-right me-1"></i> View Full Project
            </button>
            <button class="modal-btn secondary" onclick="closeProjectModal()">Close</button>
        </div>

    </div>
</div>{{-- /.modal-overlay --}}


<script>
/* ══════════════════════════════════════════════════════════════
   CALENDAR WIDGET JS
   Monthly-progress system — no milestones.
   Each project has a single deadline dot on the calendar.
   Clicking a dot opens the modal showing:
     - cumulative planned % vs actual % for the current month
     - the monthly inspection date
     - days until / overdue status
══════════════════════════════════════════════════════════════ */

// ── DATA ──
// TO WIRE BACKEND: replace with const projectsData = @json($calendarProjects);
// Each object: { id, code, name, contractor, location,
//                deadline (YYYY-MM-DD), status,
//                planned (%), progress (%), inspectionDate (YYYY-MM-DD) }
//
// Status values: 'overdue' | 'critical' | 'at-risk' | 'on-track' | 'completed'
const projectsData = [
    {
        id: 50, code: 'PRJ-2026-050',
        name: 'Certification',
        contractor: 'Tech Solutions Inc', location: 'Building A',
        deadline: '2026-04-08', status: 'overdue',
        planned: 90, progress: 85,
        inspectionDate: '2026-04-06'
    },
    {
        id: 15, code: 'PRJ-2026-015',
        name: 'Science Building Phase 2',
        contractor: 'ABC Construction Co', location: 'Science Building',
        deadline: '2026-04-30', status: 'critical',
        planned: 35, progress: 28,
        inspectionDate: '2026-06-12'
    },
    {
        id: 20, code: 'PRJ-2026-020',
        name: 'Admin Building Renovation',
        contractor: 'TechNet Solutions', location: 'Admin Building',
        deadline: '2026-07-20', status: 'at-risk',
        planned: 65, progress: 55,
        inspectionDate: '2026-06-18'
    },
    {
        id: 35, code: 'PRJ-2026-035',
        name: 'HVAC Installation',
        contractor: 'Climate Control Ltd', location: 'Building D',
        deadline: '2026-06-22', status: 'at-risk',
        planned: 50, progress: 45,
        inspectionDate: '2026-06-20'
    },
    {
        id: 25, code: 'PRJ-2026-025',
        name: 'Interior Finishing',
        contractor: 'Design Innovations', location: 'Building E',
        deadline: '2026-06-25', status: 'on-track',
        planned: 68, progress: 68,
        inspectionDate: '2026-06-23'
    },
    {
        id: 40, code: 'PRJ-2026-040',
        name: 'Roof Work Installation',
        contractor: 'Structural Builders', location: 'Building F',
        deadline: '2026-07-05', status: 'on-track',
        planned: 50, progress: 50,
        inspectionDate: '2026-07-02'
    },
    {
        id: 22, code: 'PRJ-2026-022',
        name: 'Library Network Upgrade',
        contractor: 'NetCore Systems', location: 'Library',
        deadline: '2026-06-30', status: 'on-track',
        planned: 78, progress: 80,
        inspectionDate: '2026-06-28'
    },
    {
        id: 30, code: 'PRJ-2026-030',
        name: 'Site Preparation & Clearing',
        contractor: 'Earthworks Contractors', location: 'Building A',
        deadline: '2026-03-30', status: 'completed',
        planned: 100, progress: 100,
        inspectionDate: '2026-03-28'
    },
    {
        id: 5, code: 'PRJ-2026-005',
        name: 'Environmental Survey',
        contractor: 'EcoScience Consulting', location: 'Campus',
        deadline: '2026-03-15', status: 'completed',
        planned: 100, progress: 100,
        inspectionDate: '2026-03-13'
    },
];

// ── STATE ──
const calToday    = new Date();
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

// ── MONTH VIEW ──
function generateMonthCalendar() {
    const container  = document.getElementById('single-month-calendar');
    container.innerHTML = '';
    container.appendChild(createMonthCalendar(calYear, calMonth, calMonthNames[calMonth], true));
    document.getElementById('month-year-display').textContent =
        `${calMonthNames[calMonth]} ${calYear}`;
    document.getElementById('calendar-title').textContent =
        `Project Deadlines — ${calMonthNames[calMonth]} ${calYear}`;
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

        // Deadline dots — one per project whose deadline falls on this day
        const hits = projectsData.filter(p =>
            new Date(p.deadline).toDateString() === dateObj.toDateString()
        );
        if (hits.length > 0) {
            const dotsRow = document.createElement('div');
            dotsRow.className = 'event-dots-container';
            hits.forEach(p => {
                const dot = document.createElement('div');
                dot.className   = `event-dot status-${p.status}`;
                dot.title       = p.code; // native tooltip fallback
                dot.setAttribute('data-tooltip', p.code);
                dot.setAttribute('data-project-id', p.id);
                dot.onclick = (e) => { e.stopPropagation(); showProjectModal(p.id); };
                dotsRow.appendChild(dot);
            });
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
            `Project Deadline Calendar — ${calYear}`;
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

// ── MODAL ──
// Shows monthly cumulative progress (planned vs actual), not milestone data.
function showProjectModal(projectId) {
    const p = projectsData.find(x => x.id === projectId);
    if (!p) return;

    const daysUntil = Math.ceil(
        (new Date(p.deadline) - calToday) / (1000 * 60 * 60 * 24)
    );

    // Header
    document.getElementById('modal-project-code').innerHTML =
        `<span class="project-code-badge">${p.code}</span>`;
    document.getElementById('modal-project-contractor').textContent =
        `${p.contractor} · ${p.location}`;

    // Basic details
    document.getElementById('modal-project-name').textContent = p.name;

    const sb = document.getElementById('modal-project-status');
    sb.className   = 'status-badge status-' + p.status;
    sb.textContent = p.status.charAt(0).toUpperCase()
                   + p.status.slice(1).replace('-', ' ');

    document.getElementById('modal-project-deadline').textContent =
        new Date(p.deadline).toLocaleDateString('en-US', {
            year: 'numeric', month: 'long', day: 'numeric'
        });

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
        const inspLabel = new Date(p.inspectionDate).toLocaleDateString('en-US', {
            month: 'long', day: 'numeric', year: 'numeric'
        });
        document.getElementById('modal-inspection-text').textContent =
            'Inspection date: ' + inspLabel;
        inspRow.style.display = 'flex';
    } else {
        inspRow.style.display = 'none';
    }

    // Days to deadline / overdue banner
    const daysDiv = document.getElementById('modal-days-deadline');
    if (p.status === 'completed') {
        daysDiv.className   = 'days-to-deadline safe';
        daysDiv.textContent = '✓ Project Completed';
    } else if (daysUntil < 0) {
        daysDiv.className   = 'days-to-deadline urgent';
        daysDiv.textContent = `⚠ OVERDUE by ${Math.abs(daysUntil)} day${Math.abs(daysUntil) !== 1 ? 's' : ''}`;
    } else if (daysUntil <= 7) {
        daysDiv.className   = 'days-to-deadline warning';
        daysDiv.textContent = `⏱ ${daysUntil} day${daysUntil !== 1 ? 's' : ''} until deadline`;
    } else {
        daysDiv.className   = 'days-to-deadline safe';
        daysDiv.textContent = `📅 ${daysUntil} days until deadline`;
    }

    // View button links to the project detail page
    document.getElementById('modal-view-btn').onclick = () => {
        window.location.href = `/admin/projects/${p.id}`;
    };

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
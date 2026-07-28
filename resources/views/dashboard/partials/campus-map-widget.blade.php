{{--
╔══════════════════════════════════════════════════════════════════╗
║  CAMPUS MAP WIDGET                                               ║
║  Interactive Leaflet map of all campus project locations         ║
║                                                                  ║
║  CSS is in: public/css/admin/dashboard/campus-map-widget.css     ║
║  (or whichever CSS file you moved the dashboard styles into)     ║
║                                                                  ║
║  Requires Leaflet (already loaded in layouts.app):               ║
║    leaflet@1.9.4  + leaflet.markercluster@1.4.1                 ║
║                                                                  ║
║  TO WIRE BACKEND:                                                ║
║    Replace the hardcoded `mockProjects` array with:             ║
║      const mockProjects = @json($mapProjects);                  ║
║    Each item needs: id, code, title, status, progress,           ║
║    updated (YYYY-MM-DD), lat, lng, description                   ║
╚══════════════════════════════════════════════════════════════════╝
--}}
@php $mapProjects = $mapProjects ?? []; @endphp
<div class="map-widget-wrap">

    {{-- ── HEADER ── --}}
    <div class="map-widget-header">
        <div class="map-widget-title">
            <i class="bi bi-geo-alt-fill"></i>
            <div>
                <h3>Campus Project Locations</h3>
                <p>Interactive map of all projects across campus</p>
            </div>
        </div>
        <div class="map-project-count">
            <i class="bi bi-pin-map-fill me-1"></i>
            <span id="projectCountDisplay">9 Projects</span>
        </div>
    </div>

    {{-- ── FILTER BAR ── --}}
    <div class="map-filter-bar">
        <span class="map-filter-label">Filter by status:</span>

        <button class="status-filter-btn" data-filter="all">
            <i class="bi bi-funnel-fill"></i> All
        </button>
        <button class="status-filter-btn" data-filter="Ongoing">
            <span class="map-btn-dot" style="background:#3b82f6;"></span> Ongoing
        </button>
        <button class="status-filter-btn" data-filter="Completed">
            <span class="map-btn-dot" style="background:#10b981;"></span> Completed
        </button>
        <button class="status-filter-btn" data-filter="Suspended">
            <span class="map-btn-dot" style="background:#f59e0b;"></span> Suspended
        </button>
        <button class="status-filter-btn" data-filter="Cancelled">
            <span class="map-btn-dot" style="background:#ef4444;"></span> Cancelled
        </button>
    </div>

    {{-- ── MAP CONTAINER — Leaflet renders here ── --}}
    <div class="map-container">
        <div id="campusMap"></div>
    </div>

    {{-- ── LEGEND ── --}}
    <div class="map-legend-strip">
        <div class="map-legend-item">
            <div class="map-legend-dot" style="background:#3b82f6;"></div>
            <span><strong>Ongoing</strong> — Active projects in progress</span>
        </div>
        <div class="map-legend-item">
            <div class="map-legend-dot" style="background:#10b981;"></div>
            <span><strong>Completed</strong> — Finished projects</span>
        </div>
        <div class="map-legend-item">
            <div class="map-legend-dot" style="background:#f59e0b;"></div>
            <span><strong>Suspended</strong> — Paused projects</span>
        </div>
        <div class="map-legend-item">
            <div class="map-legend-dot" style="background:#ef4444;"></div>
            <span><strong>Cancelled</strong> — Terminated projects</span>
        </div>
    </div>

</div>{{-- /.map-widget-wrap --}}


<script>
/* ══════════════════════════════════════════════════════════════
   CAMPUS MAP WIDGET JS
   Leaflet map with clustered markers and status-based filtering.

   Pin colors:
     Ongoing   → blue  (#3b82f6)
     Completed → green (#10b981)
     Suspended → amber (#f59e0b)
     Cancelled → red   (#ef4444)

   Clicking a pin opens a Leaflet popup with:
     - project code + title
     - description
     - monthly progress bar (cumulative actual %)
     - last updated date
     - "View Project Details" button → /admin/projects/{id}
══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {

    // ── CAMPUS CENTER ──
    // TO WIRE BACKEND: pull from a config or env variable
    const CAMPUS_LAT   = 13.4059;
    const CAMPUS_LNG   = 123.3758;
    const DEFAULT_ZOOM = 16;

    // ── PROJECT DATA ──
    // TO WIRE BACKEND: replace with const mockProjects = @json($mapProjects);
    const mockProjects = [
        {
            id: 1,
            code: 'PROJ-001',
            title: 'Main Library Renovation',
            status: 'Ongoing',
            progress: 45,
            updated: '2026-04-07',
            lat: 13.4062, lng: 123.3755,
            description: '5-story library renovation with digital facilities'
        },
        {
            id: 2,
            code: 'PROJ-002',
            title: 'North Parking Lot Expansion',
            status: 'Completed',
            progress: 100,
            updated: '2026-03-15',
            lat: 13.4055, lng: 123.3762,
            description: 'Additional 200-space parking facility'
        },
        {
            id: 3,
            code: 'PROJ-003',
            title: 'Sports Complex - Phase 1',
            status: 'Suspended',
            progress: 62,
            updated: '2026-03-20',
            lat: 13.4068, lng: 123.3748,
            description: 'Basketball courts and swimming pool construction'
        },
        {
            id: 4,
            code: 'PROJ-004',
            title: 'IT Laboratory Setup',
            status: 'Ongoing',
            progress: 78,
            updated: '2026-04-05',
            lat: 13.4052, lng: 123.3758,
            description: 'State-of-the-art computer lab with 80 workstations'
        },
        {
            id: 5,
            code: 'PROJ-005',
            title: 'Student Dormitory Block C',
            status: 'Ongoing',
            progress: 35,
            updated: '2026-04-01',
            lat: 13.4070, lng: 123.3765,
            description: '6-story dormitory for 300 students'
        },
        {
            id: 6,
            code: 'PROJ-006',
            title: 'Campus Road Rehabilitation',
            status: 'Completed',
            progress: 100,
            updated: '2026-02-28',
            lat: 13.4045, lng: 123.3750,
            description: 'Complete resurfacing of main campus road'
        },
        {
            id: 7,
            code: 'PROJ-007',
            title: 'Health Center Expansion',
            status: 'Ongoing',
            progress: 54,
            updated: '2026-04-06',
            lat: 13.4058, lng: 123.3745,
            description: 'New emergency ward and laboratory facilities'
        },
        {
            id: 8,
            code: 'PROJ-008',
            title: 'Administrative Building Renovation',
            status: 'Cancelled',
            progress: 20,
            updated: '2026-03-10',
            lat: 13.4065, lng: 123.3760,
            description: 'Modernization of main admin building'
        },
        {
            id: 9,
            code: 'PROJ-009',
            title: 'Power Substation Installation',
            status: 'Ongoing',
            progress: 88,
            updated: '2026-04-04',
            lat: 13.4050, lng: 123.3755,
            description: 'New electrical infrastructure for campus expansion'
        },
    ];

    // ── STATUS COLOR MAP ──
    const statusColors = {
        'Ongoing':   '#3b82f6',
        'Completed': '#10b981',
        'Suspended': '#f59e0b',
        'Cancelled': '#ef4444',
    };

    // ── INIT MAP ──
    // Stored globally so the dashboard tab-switch can call invalidateSize()
    window.campusMap = L.map('campusMap').setView([CAMPUS_LAT, CAMPUS_LNG], DEFAULT_ZOOM);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 20,
        minZoom: 14,
    }).addTo(window.campusMap);

    // ── CLUSTER GROUP ──
    const markerClusters = L.markerClusterGroup({
        maxClusterRadius: 80,
        iconCreateFunction: function (cluster) {
            const count = cluster.getChildCount();
            return L.divIcon({
                html: `<div style="
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white; width: 40px; height: 40px; border-radius: 50%;
                    display: flex; align-items: center; justify-content: center;
                    font-weight: 700; font-size: 14px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.25);
                ">${count}</div>`,
                className: 'custom-cluster',
                iconSize: [40, 40],
            });
        },
    });

    // ── BUILD MARKERS ──
    const markers = {};

    mockProjects.forEach(project => {
        const color = statusColors[project.status] || '#6b7280';

        const icon = L.divIcon({
            html: `<div style="
                background: ${color}; color: white;
                width: 32px; height: 32px; border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                font-size: 15px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                border: 3px solid white;
                cursor: pointer;
                transition: transform 0.15s;
            "><i class="bi bi-pin-fill"></i></div>`,
            className: 'custom-marker',
            iconSize:    [32, 32],
            iconAnchor:  [16, 32],
            popupAnchor: [0, -34],
        });

        const popup = `
            <div style="width:280px; font-family:'Segoe UI',sans-serif; padding:2px;">
                <div style="margin-bottom:8px;">
                    <span style="
                        display:inline-block; background:${color}; color:white;
                        padding:2px 10px; border-radius:4px;
                        font-size:11px; font-weight:700; text-transform:uppercase;
                    ">${project.status}</span>
                </div>
                <h4 style="margin:0 0 4px; font-size:14px; font-weight:700; color:#111827; line-height:1.3;">
                    ${project.code}: ${project.title}
                </h4>
                <p style="margin:0 0 10px; font-size:12px; color:#6b7280; line-height:1.45;">
                    ${project.description}
                </p>
                <div style="padding-top:10px; border-top:1px solid #e5e7eb;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:5px;">
                        <span style="color:#6b7280;">Progress</span>
                        <strong style="color:#111827;">${project.progress}%</strong>
                    </div>
                    <div style="background:#e5e7eb; border-radius:4px; height:6px; overflow:hidden;">
                        <div style="background:${color}; height:100%; width:${project.progress}%; border-radius:4px;"></div>
                    </div>
                    <div style="font-size:11px; color:#9ca3af; margin-top:6px;">
                        Last updated: ${project.updated}
                    </div>
                </div>
                <button
                    onclick="window.location.href='/admin/projects/${project.id}'"
                    style="
                        width:100%; margin-top:10px; padding:7px 12px;
                        background:${color}; color:white; border:none;
                        border-radius:6px; font-size:12px; font-weight:600;
                        cursor:pointer; transition:opacity 0.2s;
                    "
                    onmouseover="this.style.opacity='0.88'"
                    onmouseout="this.style.opacity='1'"
                >
                    View Project Details →
                </button>
            </div>
        `;

        const marker = L.marker([project.lat, project.lng], { icon });
        marker.bindPopup(popup, { maxWidth: 300 });
        marker.options.projectStatus = project.status;

        markerClusters.addLayer(marker);
        markers[project.id] = { marker, project };
    });

    window.campusMap.addLayer(markerClusters);

    // ── FILTER ──
    let activeFilter = 'all';

    document.querySelectorAll('.status-filter-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            activeFilter = this.getAttribute('data-filter');

            // Reset all buttons
            document.querySelectorAll('.status-filter-btn').forEach(b => {
                b.style.background  = '';
                b.style.borderColor = '';
                b.style.color       = '';
                b.classList.remove('active');
            });

            // Highlight active button
            const activeBg = statusColors[activeFilter] || '#2563eb';
            this.style.background  = activeBg;
            this.style.borderColor = activeBg;
            this.style.color       = 'white';
            this.classList.add('active');

            // Show / hide markers
            let visibleCount = 0;
            Object.values(markers).forEach(({ marker, project }) => {
                const match = activeFilter === 'all' || project.status === activeFilter;
                if (match) {
                    if (!markerClusters.hasLayer(marker)) markerClusters.addLayer(marker);
                    visibleCount++;
                } else {
                    markerClusters.removeLayer(marker);
                }
            });

            document.getElementById('projectCountDisplay').textContent =
                visibleCount + ' Project' + (visibleCount !== 1 ? 's' : '');
        });
    });

    // Default: activate "All" button
    const allBtn = document.querySelector('[data-filter="all"]');
    if (allBtn) {
        allBtn.style.background  = '#2563eb';
        allBtn.style.borderColor = '#2563eb';
        allBtn.style.color       = 'white';
        allBtn.classList.add('active');
    }

    // Restore filter from previous session (optional — remove if not needed)
    const savedFilter = sessionStorage.getItem('campusMapFilter');
    if (savedFilter && savedFilter !== 'all') {
        const savedBtn = document.querySelector(`[data-filter="${savedFilter}"]`);
        if (savedBtn) savedBtn.click();
    }

    // Save filter on change
    document.querySelectorAll('.status-filter-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            sessionStorage.setItem('campusMapFilter', this.getAttribute('data-filter'));
        });
    });

    // ── ZOOM CONTROLS (bottom-right) ──
    L.control.zoom({ position: 'bottomright' }).addTo(window.campusMap);
});
</script>
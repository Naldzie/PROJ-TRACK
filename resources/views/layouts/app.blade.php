<!DOCTYPE html>
<html>
<head>
    <title>Admin</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    {{--  DIRECT CSS--}}
    <link rel="stylesheet" href="{{ asset('css/dashboard/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard/dashboard-widgets.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/projects/project-detail.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/projects/projects.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin/projects/_create_project_modal.css') }}">


    {{-- VITE CSS 
     @vite(['resources/css/app.css', 'resources/js/app.js'])
     --}}
    <!-- Leaflet Map Library -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css" />
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #eef1f7;
            font-family: 'Segoe UI', sans-serif;
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            width: 260px;
            height: 100vh;
            background: linear-gradient(160deg, #1a2540 0%, #1e3a8a 100%);
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            overflow-y: auto;
        }

        .sidebar-brand {
            padding: 24px 20px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 8px;
        }

        .sidebar-brand .brand-title {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.5px;
            line-height: 1.3;
        }

        .sidebar-brand .brand-sub {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            margin-top: 3px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .nav-section-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.3);
            padding: 12px 22px 4px;
        }

        .sidebar a {
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            padding: 10px 14px;
            margin: 2px 10px;
            display: flex;
            align-items: center;
            gap: 11px;
            border-radius: 9px;
            font-size: 13.5px;
            transition: background 0.2s, color 0.2s;
        }

        .sidebar a i {
            font-size: 16px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar a:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        .sidebar a.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            font-weight: 600;
        }

        .sidebar a.active i {
            color: #60a5fa;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 16px 12px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }

        .sidebar-footer .btn-logout {
            width: 100%;
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.85);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 9px;
            padding: 9px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
        }

        .sidebar-footer .btn-logout:hover {
            background: rgba(255,255,255,0.18);
            color: #fff;
        }

        /* ===== TOPBAR ===== */
        .topbar {
            margin-left: 260px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 30px;
            position: relative;
            background-image: url("{{ asset('images/profile-bg.jpg') }}");
            background-size: cover;
            background-position: center;
        }

        .topbar::before {
            content: "";
            position: absolute;
            inset: 0;
            background: rgba(10, 20, 50, 0.52);
        }

        .topbar > * {
            position: relative;
            z-index: 2;
        }

        /* ===== PROFILE SECTION ===== */
        .profile-section {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .profile-info {
            text-align: right;
        }

        .profile-info .name {
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            line-height: 1.3;
        }

        .profile-info .role-badge {
            display: inline-block;
            font-size: 11px;
            background: rgba(96,165,250,0.25);
            color: #93c5fd;
            border: 1px solid rgba(96,165,250,0.35);
            border-radius: 20px;
            padding: 1px 9px;
            margin-top: 3px;
            letter-spacing: 0.3px;
        }

        .profiless-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            border: 2px solid rgba(255,255,255,0.6);
            box-shadow: 0 0 0 3px rgba(255,255,255,0.15);
            flex-shrink: 0;
        }

        /* ===== CONTENT ===== */
        .content {
            margin-left: 260px;
            padding: 28px 30px;
            min-height: calc(100vh - 80px);
        }

        .card-box {
            background: white;
            padding: 24px;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-title">PROJ-TRACK SYSTEM</div>
        <div class="brand-sub">CSPC &mdash; PMU</div>
    </div>

    <span class="nav-section-label">Main</span>

    <a href="{{ route('admin.dashboard') }}"
       class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>

    <a href="{{ route('admin.projects') }}"
       class="{{ request()->routeIs('admin.projects') ? 'active' : '' }}">
        <i class="bi bi-folder2-open"></i> Projects
    </a>

    <a href="{{ route('admin.warranty') }}"
       class="{{ request()->routeIs('admin.warranty') ? 'active' : '' }}">
        <i class="bi bi-shield-check"></i> Warranty
    </a>

    <a href="{{ route('admin.email') }}"
       class="{{ request()->routeIs('admin.email') ? 'active' : '' }}">
        <i class="bi bi-envelope"></i> Email
    </a>

    <span class="nav-section-label">People</span>

    <a href="{{ route('contractors.index') }}"
       class="{{ request()->routeIs('contractors.index') ? 'active' : '' }}">
        <i class="bi bi-people"></i> Contractors
    </a>

    <a href="{{ route('admin.staff.index') }}"
       class="{{ request()->routeIs('admin.staff.*') ? 'active' : '' }}">
        <i class="bi bi-person-badge"></i> PMU Staff
    </a>

    <a href="{{ route('admin.rating') }}"
       class="{{ request()->routeIs('admin.rating') ? 'active' : '' }}">
        <i class="bi bi-star"></i> Contractor Ratings
    </a>

    <span class="nav-section-label">System</span>

    <a href="{{ route('admin.activity.logs') }}"
       class="{{ request()->routeIs('admin.activity.logs') ? 'active' : '' }}">
        <i class="bi bi-clock-history"></i> Activity Logs
    </a>

    <a href="{{ route('admin.profile') }}"
       class="{{ request()->routeIs('admin.profile') ? 'active' : '' }}">
        <i class="bi bi-gear"></i> Admin Profile
    </a>

    <div class="sidebar-footer">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-logout">
                <i class="bi bi-box-arrow-right"></i> Logout
            </button>
        </form>
    </div>
</div>

<!-- TOPBAR -->
<div class="topbar">
    <div class="profile-section">
        @include('notifications.partials.bell')
        <div class="profile-info">
            <div class="name">{{ auth()->user()->admin->full_name ?? auth()->user()->email }}</div>
            <span class="role-badge">{{ ucfirst(auth()->user()->role) }}</span>
        </div>
        <div class="profiless-avatar"
             style="background-image: url('{{ 
                auth()->user()->profile_photo 
                    ? (Str::startsWith(auth()->user()->profile_photo, 'http') 
                        ? auth()->user()->profile_photo 
                        : asset('storage/' . auth()->user()->profile_photo)) 
                    : asset('images/default-profile.png') 
             }}');">
        </div>
    </div>
</div>

<!-- CONTENT -->
<div class="content">
    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/raphael@2.1.4/raphael.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/justgage/justgage.min.js"></script>

@if(session('success'))
<script>
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: "{{ session('success') }}",
        confirmButtonColor: '#2563eb'
    });
</script>
@endif

@if($errors->any())
<script>
    Swal.fire({
        icon: 'error',
        title: 'Oops...',
        html: `{!! implode('<br>', $errors->all()) !!}`,
        confirmButtonColor: '#d33'
    });
</script>
@endif

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>

<!-- PDF.js — used by document-parser.js for autofill -->
    {{-- import() requires type="module" — without it the browser throws a silent syntax error --}}
    <script type="module">
        import * as pdfjsLib from 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.min.mjs';
        pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.4.168/build/pdf.worker.min.mjs';
        // Expose as a global so non-module scripts (document-parser.js) can access it
        window.pdfjsLib = pdfjsLib;
    </script>
    
{{-- FORM VALIDATOR JS FUNCTION(CREATE/EDIT FORM VALIDATION) --}}
<script src="{{ asset('js/admin/projects/field-validation.js') }}"></script>
{{-- CREATE/EDIT PROJECTS MODAL MULTI PAGE FORM NAVIGATION JS FUNCTION --}}
<script src="{{ asset('js/admin/projects/form-navigation.js') }}"></script>

{{-- GLOBAL TOAST NOTIFICATION --}}
@include('components.toast')

{{-- DEV DATE SIMULATOR --}}
@if (app()->environment() !== 'production')
<style>
    #dev-date-widget {
        position: fixed; bottom: 80px; right: 20px; z-index: 9999;
        display: none; font-family: 'Segoe UI', sans-serif;
    }
    #dev-date-widget.visible { display: block; }
    #dev-date-toggle {
        width: 44px; height: 44px; border-radius: 50%; border: none;
        background: #1e3a8a; color: #fff; font-size: 18px; cursor: pointer;
        box-shadow: 0 2px 12px rgba(0,0,0,0.25); transition: 0.2s;
        display: flex; align-items: center; justify-content: center;
    }
    #dev-date-toggle:hover { background: #2563eb; transform: scale(1.05); }
    #dev-date-panel {
        position: absolute; bottom: 54px; right: 0; width: 260px;
        background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.18);
        padding: 16px; display: none;
    }
    #dev-date-panel.visible { display: block; }
    #dev-date-panel h4 {
        margin: 0 0 10px; font-size: 13px; font-weight: 700; color: #1f2937;
    }
    #dev-date-panel .info-row {
        font-size: 11px; color: #6b7280; margin-bottom: 4px;
    }
    #dev-date-panel .info-row strong { color: #374151; }
    #dev-date-input {
        width: 100%; padding: 8px 10px; border: 1px solid #d1d5db;
        border-radius: 6px; font-size: 13px; margin: 8px 0;
    }
    #dev-date-apply, #dev-date-reset {
        width: 100%; padding: 8px; border: none; border-radius: 6px;
        font-size: 12px; font-weight: 600; cursor: pointer; margin-bottom: 6px;
    }
    #dev-date-apply { background: #1e3a8a; color: #fff; }
    #dev-date-apply:hover { background: #2563eb; }
    #dev-date-reset { background: #f3f4f6; color: #6b7280; }
    #dev-date-reset:hover { background: #e5e7eb; }
    .dev-badge {
        display: inline-block; background: #fef3c7; color: #92400e;
        font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 8px;
        margin-left: 4px;
    }
</style>
<div id="dev-date-widget">
    <div id="dev-date-panel">
        <h4><i class="bi bi-calendar3"></i> Date Simulator</h4>
        <div class="info-row">Real date: <strong id="dev-real-date"></strong></div>
        <div class="info-row">Simulated: <strong id="dev-sim-status">none</strong></div>
        <input type="date" id="dev-date-input">
        <button id="dev-date-apply"><i class="bi bi-check-lg"></i> Apply Date</button>
        <button id="dev-date-reset"><i class="bi bi-arrow-counterclockwise"></i> Reset to Real</button>
    </div>
    <button id="dev-date-toggle" title="Date Simulator (Ctrl+M)">
        <i class="bi bi-clock"></i>
    </button>
</div>
<script>
(function () {
    const DEV_DATE_URLS = {
        get: '{{ route("dev.date.get") }}',
        set: '{{ route("dev.date.set") }}',
        clear: '{{ route("dev.date.clear") }}',
    };

    const widget = document.getElementById('dev-date-widget');
    const panel = document.getElementById('dev-date-panel');
    const toggle = document.getElementById('dev-date-toggle');
    const input = document.getElementById('dev-date-input');
    const applyBtn = document.getElementById('dev-date-apply');
    const resetBtn = document.getElementById('dev-date-reset');
    const realDateEl = document.getElementById('dev-real-date');
    const simStatusEl = document.getElementById('dev-sim-status');

    function openPanel() {
        fetch(DEV_DATE_URLS.get)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                realDateEl.textContent = data.real_date;
                if (data.simulated_date) {
                    simStatusEl.textContent = data.simulated_date;
                    input.value = data.simulated_date;
                } else {
                    simStatusEl.textContent = 'none';
                    input.value = data.real_date;
                }
            });
        panel.classList.add('visible');
        widget.classList.add('visible');
        input.focus();
    }

    function closePanel() {
        panel.classList.remove('visible');
        widget.classList.remove('visible');
    }

    function togglePanel(e) {
        e.stopPropagation();
        if (panel.classList.contains('visible')) {
            closePanel();
        } else {
            openPanel();
        }
    }

    toggle.addEventListener('click', togglePanel);

    document.addEventListener('click', function (e) {
        if (!widget.contains(e.target)) {
            closePanel();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.key === 'm') {
            e.preventDefault();
            togglePanel(e);
        }
    });

    applyBtn.addEventListener('click', function () {
        var date = input.value;
        if (!date) return;
        fetch(DEV_DATE_URLS.set, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ date: date }),
        })
        .then(function (r) {
            if (!r.ok) throw new Error('Request failed');
            return r.json();
        })
        .then(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Date set to ' + date, timer: 1200, showConfirmButton: false });
            }
            setTimeout(function () { location.reload(); }, 800);
        })
        .catch(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Failed to set date', text: 'Session may have expired. Try logging in again.' });
            }
            closePanel();
        });
    });

    resetBtn.addEventListener('click', function () {
        fetch(DEV_DATE_URLS.clear, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })
        .then(function (r) {
            if (!r.ok) throw new Error('Request failed');
            return r.json();
        })
        .then(function () {
            simStatusEl.textContent = 'none';
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Reset to real date', timer: 1200, showConfirmButton: false });
            }
            setTimeout(function () { location.reload(); }, 800);
        })
        .catch(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Failed to reset date', text: 'Session may have expired.' });
            }
            closePanel();
        });
    });
})();
</script>
@endif
</body>
</html>